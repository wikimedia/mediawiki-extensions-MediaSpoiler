<?php

namespace MediaWiki\Extension\MediaSpoiler;

use File;
use MediaWiki\Hook\ParserMakeImageParamsHook;
use MediaWiki\Hook\ParserModifyImageHTMLHook;
use MediaWiki\Hook\ParserOptionsRegisterHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\User\Hook\UserGetDefaultOptionsHook;
use MediaWiki\User\UserOptionsLookup;
use OutputPage;
use Parser;
use ParserOptions;
use Wikimedia\Parsoid\Utils\DOMUtils;

class Hooks implements
	ParserMakeImageParamsHook,
	ParserModifyImageHTMLHook,
	ParserOptionsRegisterHook,
	UserGetDefaultOptionsHook,
	GetPreferencesHook
{
	/** @var UserOptionsLookup */
	private $userOptionsLookup;

	/** @var bool */
	private $enableLegacyMediaDOM;

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
	private const MODE_NOTLOAD = 'notload';

	/**
	 * @param UserOptionsLookup $userOptionsLookup
	 */
	public function __construct( UserOptionsLookup $userOptionsLookup ) {
		$this->userOptionsLookup = $userOptionsLookup;
		$services = MediaWikiServices::getInstance();
		$mainConfig = $services->getMainConfig();
		$config = $services->getConfigFactory()->makeConfig( 'MediaSpoiler' );
		$this->enableLegacyMediaDOM = $mainConfig->get( MainConfigNames::ParserEnableLegacyMediaDOM );
		$this->enableMark = $config->get( 'MediaSpoilerEnableMark' );
		if ( $this->enableMark ) {
			$this->options += [
				'mediaspoiler-pref-hidemarked' => self::MODE_HIDEMARKED,
			];
		}
		$this->options += [
			'mediaspoiler-pref-showall' => self::MODE_SHOWALL,
			'mediaspoiler-pref-hideall' => self::MODE_HIDEALL,
			'mediaspoiler-pref-notload' => self::MODE_NOTLOAD,
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
		$mode = $parser->getOptions()->getOption( 'mediaspoiler' );
		$doc = DOMUtils::parseHTML( $html );

		if ( $this->enableLegacyMediaDOM ) {
			// TODO: legacy media DOM support
		} else {
			$figure = $doc->getElementsByTagName( 'figure' )->item( 0 );
			if ( !$figure ) {
				return;
			}

			$type = $figure->getAttribute( 'typeof' );
			if ( $type !== 'mw:File/Frame' && $type !== 'mw:File/Thumb' ) {
				return;
			}

			$classes = preg_split( '/\s+/', $figure->getAttribute( 'class' ), -1, PREG_SPLIT_NO_EMPTY );
			if ( $mode === self::MODE_NOTLOAD ) {
				$width = $figure->firstElementChild->firstElementChild->getAttribute( 'width' );
				$figure->removeChild( $figure->firstElementChild );

				$a = $doc->createElement( 'a' );
				$a->setAttribute( 'href', $file->getDescriptionUrl() );
				$a->setAttribute( 'title', $file->getTitle()->getPrefixedText() );
				$span = $doc->createElement( 'span', $file->getTitle()->getPrefixedText() );
				$span->setAttribute( 'class', 'mw-file-element' );
				$span->setAttribute( 'data-width', $width );
				$a->appendChild( $span );
				$figure->insertBefore( $a, $figure->firstElementChild );

				$html = $doc->saveHTML( $figure );
				$parser->getOutput()->addModuleStyles( [ 'ext.mediaSpoiler.noimg.style' ] );
				return;
			} elseif ( $this->enableMark && in_array( 'spoiler', $classes ) && $mode === self::MODE_SHOWALL ) {
				// skip for audio files
				if ( !$figure->firstElementChild->firstElementChild->hasAttribute( 'height' ) ) {
					return;
				}

				$figure->setAttribute( 'class', $figure->getAttribute( 'class' ) . ' nospoiler' );
				$html = $doc->saveHTML( $figure );
				return;
			} elseif ( ( $this->enableMark && in_array( 'spoiler', $classes ) ) || $mode === self::MODE_HIDEALL ) {
				$a = $figure->firstElementChild;

				// skip for audio files
				if ( !$a->firstElementChild->hasAttribute( 'height' ) ) {
					return;
				}

				// add 'spoiler' class to all media when $mode === self::MODE_HIDEALL
				if ( !in_array( 'spoiler', $classes ) ) {
					$figure->setAttribute( 'class', $figure->getAttribute( 'class' ) . ' spoiler' );
				}

				if ( $a->tagName === 'a' ) {
					$a->removeAttribute( 'href' );
					$a->setAttribute( 'role', 'link' );
					$a->setAttribute( 'aria-disabled', 'true' );
				}

				$media = $a->firstElementChild;
				if ( $media->tagName === 'video' ) {
					$media->removeAttribute( 'controls' );
				}

				$out = $parser->getOutput();
				OutputPage::setupOOUI();
				$out->setEnableOOUI( true );
				$out->addModuleStyles( [ 'ext.mediaSpoiler.style', 'oojs-ui.styles.icons-accessibility' ] );
				$out->addModules( [ 'ext.mediaSpoiler' ] );

				$button = new \OOUI\ButtonWidget( [
					'infusable' => true,
					'label' => $parser->msg( 'mediaspoiler-viewmedia' )->text(),
					'href' => $file->getDescriptionUrl(),
					'icon' => 'eye',
					'flags' => [ 'primary', 'progressive' ],
					'classes' => [ 'spoiler-button' ],
				] );

				$coverElement = $doc->importNode(
					DOMUtils::parseHTML( '<div class="spoiler-cover">' . $button->toString() . '</div>' )
						->getElementsByTagName( 'div' )->item( 0 ),
					true
				);
				$buttonElement = $coverElement->getElementsByTagName( 'span' )->item( 0 );
				$figure->appendChild( $coverElement );

				$html = $doc->saveHTML( $figure );
			}
		}
	}

	/**
	 * @param array &$defaults
	 * @param array &$inCacheKey
	 * @param array &$lazyLoad
	 * @return void
	 */
	public function onParserOptionsRegister( &$defaults, &$inCacheKey, &$lazyLoad ) {
		$defaults['mediaspoiler'] = $this->userOptionsLookup->getDefaultOption( 'mediaspoiler' );
		$inCacheKey['mediaspoiler'] = true;
		$lazyLoad['mediaspoiler'] = function ( ParserOptions $options ) {
			$mode = $this->userOptionsLookup->getOption( $options->getUserIdentity(), 'mediaspoiler' );
			if ( !in_array( $mode, $this->options, true ) ) {
				$mode = $this->enableMark ? self::MODE_HIDEMARKED : self::MODE_SHOWALL;
			}
			return $mode;
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
