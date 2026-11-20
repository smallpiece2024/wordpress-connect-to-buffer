<?php
/**
 * アイキャッチ画像から、タイトル入りの縦長画像を作る。
 *
 * @package WCTB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * タイトル入り画像（1080×1920）の生成。
 */
class WCTB_Image {

	const WIDTH  = 1080;
	const HEIGHT = 1920;

	// アップロードディレクトリの下に作る、生成した画像の置き場所。
	const DIR = 'wctb';

	// タイトルの左右の余白（px）。
	const MARGIN = 90;

	// 帯の上下の余白（px）。
	const PADDING = 70;

	// タイトルの最大行数。超える分は「…」で切る。
	const MAX_LINES = 6;

	// 試す文字の大きさ（pt）。大きいものから順に、MAX_LINES 行に収まるものを使う。
	const FONT_SIZES = array( 64, 58, 52, 46, 40 );

	// タイトルの上に入れる固定の文面の、文字の大きさ（pt）、最大行数、タイトルとの間隔（px）。
	const LEAD_SIZE      = 34;
	const LEAD_MAX_LINES = 2;
	const LEAD_GAP       = 36;

	// 行頭に置かない文字。前の文字にくっつける。
	const NO_LINE_START = '、。，．！？!?）)」』】〕〉》・…ー々ぁぃぅぇぉっゃゅょゎァィゥェォッャュョヮ';

	// 行末に置かない文字。次の文字にくっつける。
	const NO_LINE_END = '（(「『【〔〈《';

	/**
	 * フックを登録する。
	 */
	public static function register() {
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_post' ) );
	}

	/**
	 * タイトルを描くフォントのパス。
	 *
	 * @return string
	 */
	public static function font_path() {
		/**
		 * タイトルを描くフォント（TrueType か OpenType）のパス。
		 *
		 * @param string $path フォントファイルのパス。
		 */
		return (string) apply_filters( 'wctb_title_image_font', WCTB_PLUGIN_DIR . 'assets/fonts/NotoSansJP-Bold.otf' );
	}

	/**
	 * この環境で画像を生成できるか。GD と、その FreeType 対応が要る。
	 *
	 * @return bool
	 */
	public static function is_supported() {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagettftext' ) && is_readable( self::font_path() );
	}

	/**
	 * 投稿のアイキャッチ画像からタイトル入り画像を作り、その URL を返す。
	 *
	 * 同じ画像とタイトルで作ったものが既にあれば、作り直さない。
	 *
	 * @param WP_Post $post 投稿。
	 * @return string 画像の URL。作れなければ空文字。
	 */
	public static function generate( WP_Post $post ) {
		if ( ! self::is_supported() ) {
			return '';
		}

		$source = get_attached_file( (int) get_post_thumbnail_id( $post ) );
		if ( ! $source || ! is_readable( $source ) ) {
			return '';
		}

		$title = wp_strip_all_tags( html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ) );

		/**
		 * 画像に描き込む文字。空文字なら何も描かない。
		 *
		 * @param string  $title 文字。
		 * @param WP_Post $post  投稿。
		 */
		$title = self::strip_urls( (string) apply_filters( 'wctb_title_image_text', $title, $post ) );

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::DIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$lead = self::strip_urls( WCTB_Options::lead( $post ) );
		$font = self::font_path();
		$hash = substr( md5( implode( '|', array( $lead, $title, $source, (string) filemtime( $source ), $font, WCTB_VERSION ) ) ), 0, 12 );
		$name = 'post-' . (int) $post->ID . '-' . $hash . '.jpg';
		$path = $dir . '/' . $name;
		$url  = trailingslashit( $uploads['baseurl'] ) . self::DIR . '/' . $name;

		if ( file_exists( $path ) ) {
			return $url;
		}

		wp_raise_memory_limit( 'image' );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 読めない形式なら false が返り、下で扱う。
		$image = @imagecreatefromstring( (string) file_get_contents( $source ) );
		if ( ! $image ) {
			return '';
		}

		$canvas = self::crop( $image );
		imagedestroy( $image );

		if ( '' !== $title || '' !== $lead ) {
			self::draw_title( $canvas, $title, $font, $lead );
		}

		// タイトルや画像を変えて作り直したときに、古いファイルを残さない。
		self::delete_for_post( $post->ID );

		$saved = imagejpeg( $canvas, $path, 90 );
		imagedestroy( $canvas );

		return $saved ? $url : '';
	}

	/**
	 * 画像に描く文字から URL を除く。画像の中の URL は押せず、長くて場所を取るため。
	 *
	 * URL を除いて空になった括弧も消し、空白を 1 つにまとめる。
	 *
	 * @param string $text 文字列。
	 * @return string
	 */
	public static function strip_urls( $text ) {
		$text = (string) preg_replace( '#https?://[\x21-\x7E]+#i', ' ', $text );
		$text = (string) preg_replace( '/[（(\[「『【]\s*[）)\]」』】]/u', '', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * 投稿のために生成した画像を消す。
	 *
	 * @param int $post_id 投稿 ID。
	 */
	public static function delete_for_post( $post_id ) {
		$uploads = wp_upload_dir( null, false );
		$files   = glob( trailingslashit( $uploads['basedir'] ) . self::DIR . '/post-' . (int) $post_id . '-*.jpg' );

		foreach ( (array) $files as $file ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * 画像の中央を 9:16 に切り抜き、1080×1920 にする。
	 *
	 * 元の画像の短いほうの辺をそのまま使う。横長なら縦をすべて使って左右を切り、
	 * 9:16 より縦長なら横をすべて使って上下を切る。
	 *
	 * @param resource|GdImage $image 元の画像。
	 * @return resource|GdImage
	 */
	private static function crop( $image ) {
		$width  = imagesx( $image );
		$height = imagesy( $image );

		if ( $width * self::HEIGHT > $height * self::WIDTH ) {
			$crop_height = $height;
			$crop_width  = (int) round( $height * self::WIDTH / self::HEIGHT );
		} else {
			$crop_width  = $width;
			$crop_height = (int) round( $width * self::HEIGHT / self::WIDTH );
		}

		$canvas = imagecreatetruecolor( self::WIDTH, self::HEIGHT );

		// 透過のある画像は、白の上に重ねる。
		imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 255, 255, 255 ) );

		imagecopyresampled(
			$canvas,
			$image,
			0,
			0,
			(int) floor( ( $width - $crop_width ) / 2 ),
			(int) floor( ( $height - $crop_height ) / 2 ),
			self::WIDTH,
			self::HEIGHT,
			$crop_width,
			$crop_height
		);

		return $canvas;
	}

	/**
	 * 画像の中央に半透明の帯を敷き、その上にタイトルを白で描く。
	 *
	 * 固定の文面があれば、タイトルの上に小さめの文字で描く。
	 *
	 * @param resource|GdImage $canvas 描き込む画像。
	 * @param string           $title  タイトル。
	 * @param string           $font   フォントファイルのパス。
	 * @param string           $lead   タイトルの上に入れる固定の文面。
	 */
	private static function draw_title( $canvas, $title, $font, $lead = '' ) {
		$max_width = self::WIDTH - 2 * self::MARGIN;

		$lead_lines = '' === $lead ? array() : self::wrap( self::tokenize( $lead ), self::LEAD_SIZE, $font, $max_width );
		$lead_lines = self::limit_lines( $lead_lines, self::LEAD_MAX_LINES, self::LEAD_SIZE, $font, $max_width );

		$tokens    = self::tokenize( $title );
		$size      = 0;
		$lines     = array();

		foreach ( self::FONT_SIZES as $size ) {
			$lines = self::wrap( $tokens, $size, $font, $max_width );
			if ( count( $lines ) <= self::MAX_LINES ) {
				break;
			}
		}

		if ( count( $lines ) > self::MAX_LINES ) {
			$lines = self::limit_lines( $lines, self::MAX_LINES, $size, $font, $max_width );
		} elseif ( count( $lines ) > 1 ) {
			// 最後の行だけ短くならないよう、同じ行数に収まるいちばん狭い幅で折り返し直す。
			$count = count( $lines );
			$low   = (int) ( $max_width / $count );
			$high  = $max_width;

			while ( $high - $low > 8 ) {
				$middle = (int) ( ( $low + $high ) / 2 );
				$trial  = self::wrap( $tokens, $size, $font, $middle );

				if ( count( $trial ) <= $count && self::widest( $trial, $size, $font ) <= $max_width ) {
					$high  = $middle;
					$lines = $trial;
				} else {
					$low = $middle;
				}
			}
		}

		$lead_height  = self::line_height( self::LEAD_SIZE ) * count( $lead_lines );
		$title_height = self::line_height( $size ) * count( $lines );
		$gap          = $lead_lines && $lines ? self::LEAD_GAP : 0;
		$text_height  = $lead_height + $gap + $title_height;
		$top          = (int) round( ( self::HEIGHT - $text_height ) / 2 );

		imagealphablending( $canvas, true );
		imagefilledrectangle(
			$canvas,
			0,
			$top - self::PADDING,
			self::WIDTH,
			$top + $text_height + self::PADDING,
			imagecolorallocatealpha( $canvas, 0, 0, 0, 50 )
		);

		self::draw_lines( $canvas, $lead_lines, self::LEAD_SIZE, $font, $top );
		self::draw_lines( $canvas, $lines, $size, $font, $top + $lead_height + $gap );
	}

	/**
	 * 行の高さ（px）。GD は文字の大きさを pt（96dpi）で受け取る。
	 *
	 * @param int $size 文字の大きさ（pt）。
	 * @return int
	 */
	private static function line_height( $size ) {
		return (int) round( $size * 96 / 72 * 1.45 );
	}

	/**
	 * 行を、左右の中央に白で描く。
	 *
	 * @param resource|GdImage $canvas 描き込む画像。
	 * @param string[]         $lines  行。
	 * @param int              $size   文字の大きさ（pt）。
	 * @param string           $font   フォントファイルのパス。
	 * @param int              $top    最初の行の上端（px）。
	 */
	private static function draw_lines( $canvas, array $lines, $size, $font, $top ) {
		$em          = $size * 96 / 72;
		$line_height = self::line_height( $size );
		$white       = imagecolorallocate( $canvas, 255, 255, 255 );

		foreach ( array_values( $lines ) as $index => $line ) {
			$box = imagettfbbox( $size, 0, $font, $line );
			$x   = (int) round( ( self::WIDTH - ( $box[2] - $box[0] ) ) / 2 - $box[0] );

			// 行ごとの文字の高さに左右されないよう、ベースラインは全角の枠から決める。
			$y = (int) round( $top + $index * $line_height + ( $line_height - $em ) / 2 + $em * 0.88 );

			imagettftext( $canvas, $size, 0, $x, $y, $white, $font, $line );
		}
	}

	/**
	 * 行数が上限を超えていれば、超える分を捨てて、最後の行を「…」で終える。
	 *
	 * @param string[] $lines     行。
	 * @param int      $max_lines 行数の上限。
	 * @param int      $size      文字の大きさ（pt）。
	 * @param string   $font      フォントファイルのパス。
	 * @param int      $max_width 行の最大幅（px）。
	 * @return string[]
	 */
	private static function limit_lines( array $lines, $max_lines, $size, $font, $max_width ) {
		if ( count( $lines ) <= $max_lines ) {
			return $lines;
		}

		$lines = array_slice( $lines, 0, $max_lines );
		$last  = $lines[ $max_lines - 1 ];

		while ( '' !== $last && self::text_width( $last . '…', $size, $font ) > $max_width ) {
			$last = (string) preg_replace( '/.$/us', '', $last );
		}

		$lines[ $max_lines - 1 ] = $last . '…';

		return $lines;
	}

	/**
	 * 文字列を、行を分けてよい単位に分ける。
	 *
	 * 英数字の並びは 1 つの単位にし、単語の途中で折り返さない。日本語は 1 文字ずつ分けるが、
	 * 句読点や閉じ括弧は前の文字に、開き括弧は次の文字にくっつける。
	 *
	 * @param string $text 文字列。
	 * @return string[]
	 */
	private static function tokenize( $text ) {
		preg_match_all( '/[\x21-\x7E]+|./us', $text, $matches );

		$tokens = array();
		$glue   = false;

		foreach ( $matches[0] as $piece ) {
			$last = count( $tokens ) - 1;

			if ( ' ' === $piece ) {
				$tokens[] = ' ';
				$glue     = false;
				continue;
			}

			$first_char = (string) preg_replace( '/^(.).*$/us', '$1', $piece );
			$last_char  = (string) preg_replace( '/^.*(.)$/us', '$1', $piece );

			if ( $last >= 0 && ' ' !== $tokens[ $last ] && ( $glue || false !== strpos( self::NO_LINE_START, $first_char ) ) ) {
				$tokens[ $last ] .= $piece;
			} else {
				$tokens[] = $piece;
			}

			$glue = false !== strpos( self::NO_LINE_END, $last_char );
		}

		return $tokens;
	}

	/**
	 * 単位の並びを、幅に収まる行に分ける。
	 *
	 * @param string[] $tokens    tokenize() の結果。
	 * @param int      $size      文字の大きさ（pt）。
	 * @param string   $font      フォントファイルのパス。
	 * @param int      $max_width 行の最大幅（px）。
	 * @return string[]
	 */
	private static function wrap( array $tokens, $size, $font, $max_width ) {
		$lines   = array();
		$current = '';

		foreach ( $tokens as $token ) {
			if ( ' ' === $token && '' === $current ) {
				continue;
			}

			if ( self::text_width( $current . $token, $size, $font ) <= $max_width ) {
				$current .= $token;
				continue;
			}

			if ( '' !== $current ) {
				$lines[] = rtrim( $current );
				$current = '';
			}

			if ( ' ' === $token ) {
				continue;
			}

			if ( self::text_width( $token, $size, $font ) <= $max_width ) {
				$current = $token;
				continue;
			}

			// 1 行に収まらない長い単語や URL は、文字の途中で折り返す。
			preg_match_all( '/./us', $token, $chars );

			foreach ( $chars[0] as $char ) {
				if ( '' !== $current && self::text_width( $current . $char, $size, $font ) > $max_width ) {
					$lines[] = $current;
					$current = '';
				}

				$current .= $char;
			}
		}

		if ( '' !== rtrim( $current ) ) {
			$lines[] = rtrim( $current );
		}

		return $lines;
	}

	/**
	 * いちばん長い行の幅（px）。
	 *
	 * @param string[] $lines 行。
	 * @param int      $size  文字の大きさ（pt）。
	 * @param string   $font  フォントファイルのパス。
	 * @return int
	 */
	private static function widest( array $lines, $size, $font ) {
		$widest = 0;

		foreach ( $lines as $line ) {
			$widest = max( $widest, self::text_width( $line, $size, $font ) );
		}

		return $widest;
	}

	/**
	 * 文字列を描いたときの幅（px）。
	 *
	 * @param string $text 文字列。
	 * @param int    $size 文字の大きさ（pt）。
	 * @param string $font フォントファイルのパス。
	 * @return int
	 */
	private static function text_width( $text, $size, $font ) {
		if ( '' === $text ) {
			return 0;
		}

		$box = imagettfbbox( $size, 0, $font, $text );

		return (int) abs( $box[2] - $box[0] );
	}
}
