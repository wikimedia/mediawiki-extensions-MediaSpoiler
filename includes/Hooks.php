<?php

namespace MediaWiki\Extension\MediaSpoiler;

use ConfigException;
use File;
use MediaWiki\Hook\OutputPageBeforeHTMLHook;
use MediaWiki\Hook\ParserMakeImageParamsHook;
use MediaWiki\Hook\ParserModifyImageHTMLHook;
use MediaWiki\Hook\ParserOptionsRegisterHook;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\Title\Title;
use MediaWiki\User\Hook\UserGetDefaultOptionsHook;
use MediaWiki\User\User;
use MediaWiki\User\UserOptionsLookup;
use OutputPage;
use Parser;
use ParserOptions;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Parsoid\Utils\DOMUtils;

class Hooks implements
	ParserMakeImageParamsHook,
	ParserModifyImageHTMLHook,
	OutputPageBeforeHTMLHook,
	ParserOptionsRegisterHook,
	UserGetDefaultOptionsHook,
	GetPreferencesHook
{
	/** @var UserOptionsLookup */
	private $userOptionsLookup;

	/** @var bool */
	private $enableMark;

	/** @var string[] */
	private $options = [];

	/** @var string hide media marked as sensitive */
	private const MODE_HIDEMARKED = 'hidemarked';

	/** @var string show all media */
	private const MODE_SHOWALL = 'showall';

	/** @var string hide all media */
	private const MODE_HIDEALL = 'hideall';

	/** @var string show links to media description pages only */
	private const MODE_NOIMG = 'noimg';

	/**
	 * @param UserOptionsLookup $userOptionsLookup
	 */
	public function __construct( UserOptionsLookup $userOptionsLookup ) {
		$this->userOptionsLookup = $userOptionsLookup;
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'MediaSpoiler' );
		$this->enableMark = $config->get( 'MediaSpoilerEnableMark' );
		if ( $this->enableMark ) {
			$this->options += [
				'mediaspoiler-pref-hidemarked' => self::MODE_HIDEMARKED,
			];
		}
		$this->options += [
			'mediaspoiler-pref-showall' => self::MODE_SHOWALL,
			'mediaspoiler-pref-hideall' => self::MODE_HIDEALL,
			'mediaspoiler-pref-noimg' => self::MODE_NOIMG,
		];
	}

	/**
	 * @param Title $title
	 * @param File $file
	 * @param array &$params
	 * @param Parser $parser
	 * @return void
	 */
	public function onParserMakeImageParams( $title, $file, &$params, $parser ) {
		if ( !$this->enableMark ) {
			return;
		}

		$hasVisibleCaption = isset( $params['frame']['framed'] )
			|| isset( $params['frame']['thumbnail'] )
			|| isset( $params['frame']['manualthumb'] );
		if ( !$hasVisibleCaption ) {
			return;
		}

		// exclude marked files from page image candidates
		if ( is_object( $file ) ) {
			$classes = preg_split( '/\s+/', ( $params['frame']['class'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
			if ( in_array( 'spoiler', $classes ) && !in_array( 'notpageimage', $classes ) ) {
				$params['frame']['class'] .= ' notpageimage';
			}
		}
	}

	/**
	 * @param Parser $parser
	 * @param File $file
	 * @param array $params
	 * @param string &$html
	 * @return void
	 */
	public function onParserModifyImageHTML( Parser $parser, File $file,
		array $params, string &$html ): void {
		if ( !$file->exists() ) {
			return;
		}

		$mode = $parser->getOptions()->getOption( 'mediaspoiler' );
		if ( $mode !== self::MODE_NOIMG ) {
			return;
		}

		$enableLegacyMediaDOM = MediaWikiServices::getInstance()
			->getMainConfig()
			->get( MainConfigNames::ParserEnableLegacyMediaDOM );
		if ( $enableLegacyMediaDOM ) {
			throw new ConfigException( 'MediaSpoiler requires $wgParserEnableLegacyMediaDOM to be false' );
		}

		$hasVisibleCaption = isset( $params['frame']['framed'] )
			|| isset( $params['frame']['thumbnail'] )
			|| isset( $params['frame']['manualthumb'] );
		$mediaType = $file->getMediaType();
		if ( !$hasVisibleCaption ) {
			// Skip for images without visible captions
			if ( $mediaType === MEDIATYPE_BITMAP || $mediaType === MEDIATYPE_DRAWING ) {
				return;
			}
			$selector = "[typeof~='mw:File']";
		} else {
			$selector = "figure[typeof~='mw:File/Thumb'], figure[typeof~='mw:File/Frame']";
		}
		$doc = DOMUtils::parseHTML( $html );
		$figure = DOMCompat::querySelector( $doc, $selector );
		if ( !$figure ) {
			return;
		}

		$title = $file->getTitle();
		$link = Linker::link(
			$title,
			Html::element( 'span', [
				'class' => 'mw-file-element mw-broken-media',
				'data-width' => $params['handler']['width'] ?? null,
				'data-height' => $params['handler']['height'] ?? null,
			], $title->getPrefixedText() ),
			[],
			[],
			[ 'known', 'noclasses' ]
		);
		$linkElement = $doc->importNode( DOMCompat::querySelector( DOMUtils::parseHTML( $link ), 'a' ), true );
		$figure->removeChild( $figure->firstChild );
		$figure->insertBefore( $linkElement, $figure->firstChild );
		$html = $doc->saveHTML( $figure );
	}

	/**
	 * @param OutputPage $out
	 * @param string &$text
	 * @return void
	 */
	public function onOutputPageBeforeHTML( $out, &$text ) {
		$mode = $this->userOptionsLookup->getOption( $out->getUser(), 'mediaspoiler' );

		if ( $mode === self::MODE_HIDEALL ) {
			$selector = "figure[typeof~='mw:File/Thumb'], figure[typeof~='mw:File/Frame']";
		} elseif ( $this->enableMark && $mode === self::MODE_HIDEMARKED ) {
			$selector = "figure.spoiler[typeof~='mw:File/Thumb'], figure.spoiler[typeof~='mw:File/Frame']";
		} else {
			return;
		}

		$enableLegacyMediaDOM = MediaWikiServices::getInstance()
			->getMainConfig()
			->get( MainConfigNames::ParserEnableLegacyMediaDOM );
		if ( $enableLegacyMediaDOM ) {
			throw new ConfigException( 'MediaSpoiler requires $wgParserEnableLegacyMediaDOM to be false' );
		}

		$doc = DOMUtils::parseHTML( $text );
		$figures = DOMCompat::querySelectorAll( $doc, $selector );
		if ( count( $figures ) === 0 ) {
			return;
		}
		foreach ( $figures as $figure ) {
			/** @var $a \DOMElement */
			$a = $figure->firstChild;
			'@phan-var \DOMElement $a';

			// Skip for audio files
			/** @var $img \DOMElement */
			$img = $a->firstChild;
			'@phan-var \DOMElement $img';
			if ( !$img->hasAttribute( 'height' ) ) {
				continue;
			}

			// Add 'spoiler' class to all media when $mode === self::MODE_HIDEALL
			$classes = DOMCompat::getClassList( $figure );
			if ( !$classes->contains( 'spoiler' ) ) {
				$classes->add( 'spoiler' );
			}

			$href = '';
			if ( $a->tagName === 'a' ) {
				$href = $a->getAttribute( 'href' );
				$a->removeAttribute( 'href' );
				$a->setAttribute( 'role', 'link' );
				$a->setAttribute( 'aria-disabled', 'true' );
			}

			$media = $a->firstChild;
			'@phan-var \DOMElement $media';
			if ( $media->tagName === 'video' ) {
				$media->removeAttribute( 'controls' );
			}

			if ( !$href ) {
				$href = Title::makeTitle( NS_FILE, $media->getAttribute( 'data-mwtitle' ) )->getLinkURL();
			}

			$out->enableOOUI();
			$out->addModuleStyles( [ 'ext.mediaSpoiler.style', 'oojs-ui.styles.icons-accessibility' ] );
			$out->addModules( [ 'ext.mediaSpoiler' ] );

			$button = new \OOUI\ButtonWidget( [
				'infusable' => true,
				'label' => $out->msg( 'mediaspoiler-viewmedia' )->text(),
				'href' => $href,
				'icon' => 'eye',
				'flags' => [ 'primary', 'progressive' ],
				'classes' => [ 'spoiler-button' ],
			] );

			$coverElement = $doc->importNode(
				DOMUtils::parseHTML( Html::rawElement( 'div', [ 'class' => 'spoiler-cover' ], $button->toString() ) )
					->firstChild,
				true
			);
			$figure->appendChild( $coverElement );
		}

		$text = $doc->saveHTML();
	}

	/**
	 * @param array &$defaults
	 * @param array &$inCacheKey
	 * @param array &$lazyLoad
	 * @return void
	 */
	public function onParserOptionsRegister( &$defaults, &$inCacheKey, &$lazyLoad ) {
		$mode = $this->userOptionsLookup->getDefaultOption( 'mediaspoiler' );
		$defaults['mediaspoiler'] = $mode === self::MODE_NOIMG ? self::MODE_NOIMG : null;
		$inCacheKey['mediaspoiler'] = true;
		$lazyLoad['mediaspoiler'] = function ( ParserOptions $options ) {
			$mode = $this->userOptionsLookup->getOption( $options->getUserIdentity(), 'mediaspoiler' );
			return $mode === self::MODE_NOIMG ? self::MODE_NOIMG : null;
		};
	}

	/**
	 * @param array &$defaultOptions
	 * @return void
	 */
	public function onUserGetDefaultOptions( &$defaultOptions ) {
		$mode = $defaultOptions['mediaspoiler'] ?? '';
		if ( in_array( $mode, $this->options, true ) ) {
			return;
		}

		if ( $mode !== '' ) {
			LoggerFactory::getInstance( 'MediaSpoiler' )
				->error( "Misconfiguration: wgDefaultUserOptions['mediaspoiler'] is not a valid mode", [
					'valid_modes' => array_values( $this->options ),
					'configured_default' => $mode,
				] );
		}
		$defaultOptions['mediaspoiler'] = $this->enableMark ? self::MODE_HIDEMARKED : self::MODE_SHOWALL;
	}

	/**
	 * @param User $user
	 * @param array &$preferences
	 * @return void
	 */
	public function onGetPreferences( $user, &$preferences ) {
		$preferences['mediaspoiler'] = [
			'type' => 'select',
			'options-messages' => $this->options,
			'label-message' => 'mediaspoiler-pref-label',
			'help-message' => 'mediaspoiler-pref-help',
			'section' => 'rendering/files',
		];
	}
}
