<?php
/**
 * タイトル入り画像の見本を作る。日本語のタイトルが描けることを目で確かめるためのスクリプト。
 *
 * 横長の画像を作ってアイキャッチ画像にし、生成した画像を tests/output/ に書き出す。
 * 作った投稿と画像は、実行後に消す。
 *
 * 実行: npx @wordpress/env run cli wp eval-file wp-content/plugins/wordpress-connect-to-buffer/tests/sample-image.php
 *
 * @package WCTB
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( ! WCTB_Image::is_supported() ) {
	WP_CLI::error( 'GD の FreeType 対応か、フォントファイルがない。' );
}

$wctb_titles = array(
	'short'  => '京都で食べたい抹茶スイーツ',
	'middle' => '【2026年版】初心者でも失敗しない、WordPress プラグインの選び方と「おすすめ」10選',
	'long'   => 'はじめての一人旅で訪れた北海道の小さな港町で、地元の人に教えてもらった朝市の海鮮丼と、夕暮れの灯台までの散歩道がとても良かったので、行き方と費用と持ち物をすべてまとめて詳しく紹介します（保存版）',
);

// タイトルの上に入れる固定の文面。long には、2 行に収まらない長さのものを使う。
$wctb_leads = array(
	'short'  => '',
	'middle' => 'サンプルサイトに新しい記事を公開しました',
	'long'   => 'サンプルサイトという名前のとても長い名前のウェブサイトに、新しい記事を公開しましたので、ぜひプロフィールのリンクからご覧ください',
);
$wctb_lead  = '';
add_filter(
	'wctb_lead_text',
	static function () use ( &$wctb_lead ) {
		return $wctb_lead;
	}
);

$wctb_uploads = wp_upload_dir();
$wctb_source  = trailingslashit( $wctb_uploads['path'] ) . 'wctb-sample-source.jpg';

// 切り抜きの位置が分かるよう、左右で色の違う横長（1600×900）の画像を作る。
$wctb_image = imagecreatetruecolor( 1600, 900 );
for ( $wctb_x = 0; $wctb_x < 1600; $wctb_x++ ) {
	$wctb_ratio = $wctb_x / 1599;
	imageline(
		$wctb_image,
		$wctb_x,
		0,
		$wctb_x,
		899,
		imagecolorallocate( $wctb_image, (int) ( 30 + 200 * $wctb_ratio ), (int) ( 140 - 60 * $wctb_ratio ), (int) ( 200 - 150 * $wctb_ratio ) )
	);
}
imagefilledellipse( $wctb_image, 800, 450, 500, 500, imagecolorallocate( $wctb_image, 250, 240, 200 ) );
imagejpeg( $wctb_image, $wctb_source, 90 );
imagedestroy( $wctb_image );

$wctb_attachment = wp_insert_attachment(
	array(
		'post_title'     => 'wctb sample',
		'post_mime_type' => 'image/jpeg',
		'post_status'    => 'inherit',
	),
	$wctb_source
);

$wctb_output = WCTB_PLUGIN_DIR . 'tests/output';
wp_mkdir_p( $wctb_output );

foreach ( $wctb_titles as $wctb_key => $wctb_title ) {
	$wctb_post = wp_insert_post(
		array(
			'post_title'  => $wctb_title,
			'post_status' => 'draft',
			'post_type'   => 'post',
		)
	);
	set_post_thumbnail( $wctb_post, $wctb_attachment );
	$wctb_lead = $wctb_leads[ $wctb_key ];

	$wctb_url = WCTB_Image::generate( get_post( $wctb_post ) );
	if ( '' === $wctb_url ) {
		WP_CLI::error( '画像を生成できなかった: ' . $wctb_key );
	}

	$wctb_path = trailingslashit( $wctb_uploads['basedir'] ) . WCTB_Image::DIR . '/' . basename( $wctb_url );
	$wctb_size = getimagesize( $wctb_path );
	copy( $wctb_path, $wctb_output . '/' . $wctb_key . '.jpg' );

	WP_CLI::line( $wctb_key . ': ' . $wctb_size[0] . 'x' . $wctb_size[1] . ' ' . $wctb_url );

	// 投稿を消すと、生成した画像も消える。
	wp_delete_post( $wctb_post, true );
	WP_CLI::line( '  投稿の削除後に画像が残っているか: ' . ( file_exists( $wctb_path ) ? 'yes' : 'no' ) );
}

wp_delete_attachment( $wctb_attachment, true );

WP_CLI::success( 'tests/output/ に見本を書き出した。' );
