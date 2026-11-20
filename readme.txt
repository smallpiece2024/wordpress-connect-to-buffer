=== Connect to Buffer ===
Tags: buffer, social media, instagram, facebook, auto post
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends a post to Buffer with its title, URL and featured image when it is published, as a draft or straight into your queue.

== Description ==

Connect to Buffer sends a post to [Buffer](https://buffer.com/) when you publish it. Buffer receives the title, the URL and the featured image of the post, and shares them on the social accounts you choose.

= Features =

* **Draft or queue.** Save the post as a draft in Buffer, or add it to your queue. The default is a draft, so nothing reaches your social accounts until you schedule it in Buffer. A queued post is published automatically at the next slot of your posting schedule.
* **Several channels.** Choose any number of Buffer channels to send to.
* **Text before the title.** Add a fixed text, such as "New post on My Site", so that readers know what the title is. It is used in the text sent to Buffer and on the vertical image.
* **Vertical image with the title.** For each channel, send the featured image as it is, or turn it into a 1080×1920 image with the post title drawn on it. This suits Instagram stories.
* **Filter by tag.** Send only the posts that have at least one of the tags you set.
* **Filter by category.** Send only the posts in at least one of the categories you set. A category also covers every category below it.
* **Test without publishing.** Send one of your published posts to Buffer as a draft from the settings screen, and see whether your tags and categories match it.
* **Activity log.** See the result of the last 20 deliveries, with the error message when one failed.

= What is sent, and when =

* Only posts of the "post" type are sent. Pages and custom post types are not.
* A post is sent once, when it is published for the first time. Updating a published post does not send it again. A scheduled post is sent when it is published.
* If you set both tags and categories, a post must match both.
* Password protected posts are not sent.
* The text sent to Buffer is the text before the title (if set), the title and the URL, each on its own line.

= Text before the title =

A title alone may not tell readers what it is. Enter a fixed text in "Text before the title" on the Settings tab, for example "New post on My Site".

* In the text sent to Buffer, it is placed on the line before the title.
* On the vertical image, it is drawn above the title, in smaller and slightly darker letters. Text that does not fit in two lines is cut with an ellipsis.
* A URL in this text or in the title is not drawn on the vertical image, because nobody can click it there and it takes a lot of space. It stays in the text sent to Buffer.
* Leave it empty to send the title alone.

= Vertical image with the title =

This is for channels that use vertical images, such as Instagram stories. You turn it on for each channel.

* The center of the featured image is cropped to 9:16 and resized to 1080×1920. The shorter side of the original image is kept whole: a wide image keeps its full height and loses its left and right, and an image taller than 9:16 keeps its full width and loses its top and bottom.
* A translucent band is laid across the middle of the image, and the post title is drawn on it in white. A long title is wrapped. If it does not fit in six lines even with smaller letters, the rest is cut with an ellipsis.
* An Instagram channel with this option receives the image as a story. A 9:16 image does not fit the ratios that Instagram allows for feed posts (4:5 to 1.91:1). A story has no caption, so the title and the URL are not shown with it.
* The letters are drawn with the bundled Noto Sans JP (Bold) font, so Japanese titles work.
* The images are saved in `wp-content/uploads/wctb/`. They are deleted when the post is deleted permanently and when the plugin is uninstalled.
* If the image cannot be created, for example because the post has no featured image or the server lacks the GD extension of PHP with FreeType support, the featured image is sent as it is.

= Test =

Use the Test tab to check the plugin without publishing a new post.

1. Save your settings on the Settings tab. A test uses the saved settings.
2. Open the Test tab and choose a post. It lists up to five published posts, newest first. Each one shows whether it will be sent with the current settings, or why it will not.
3. Press "Send a test to Buffer". After sending, the Recent activity tab opens with the result. If the post you chose does not match your tags or categories, the Test tab opens again with the reason.

A test is always saved as a draft in Buffer, even when "Add it to the queue" is selected, so nothing reaches your social accounts. Delete the drafts in Buffer afterwards. A test sends to every selected channel, even if the post was sent before, and it does not change the record of what was sent.

= Images =

Buffer fetches an image from its URL. The featured image must meet these conditions. Otherwise the post is sent without an image, or Buffer returns an error.

* It is served over HTTPS. An image that is not on HTTPS is left out.
* It can be opened without logging in.
* The bot protection of your site (such as Cloudflare) does not block Buffer.

= Filters for developers =

* `wctb_should_send`: decide for each post whether it is sent.
* `wctb_post_text`: change the text sent to Buffer.
* `wctb_post_image_url`: change the URL of the image sent to Buffer. Return an empty string to send no image. The third argument is the channel ID.
* `wctb_lead_text`: change the text before the title for each post. Return an empty string to leave it out.
* `wctb_title_image_text`: change the text drawn on the vertical image. Return an empty string to draw nothing.
* `wctb_title_image_font`: change the path of the font file (TrueType or OpenType) used on the vertical image.
* `wctb_post_metadata`: change the settings that are specific to a service. Facebook and Instagram receive `type: post`. An Instagram channel with the vertical image receives `type: story`.

= External services =

This plugin connects to the API of Buffer (`https://api.buffer.com`). You need a Buffer account and an API key.

* When you open the settings screen, the plugin sends your API key to Buffer to load the list of your channels. The list is cached for one hour.
* When a post is published, or when you send a test, the plugin sends your API key, the ID of each selected channel, the text (the text before the title, the title and the URL of the post) and the URL of the image to Buffer. Buffer then fetches the image from your site.

Buffer is provided by Buffer, Inc. See its [terms of use](https://buffer.com/legal) and [privacy policy](https://buffer.com/legal#privacy-policy).

The API key is stored in the options table of your site, unless you define it in `wp-config.php`. It is deleted when the plugin is uninstalled.

== Installation ==

1. Upload the plugin to `wp-content/plugins/wordpress-connect-to-buffer`, or install the ZIP file from "Plugins > Add New > Upload Plugin". Then activate it.
2. Create an API key in the [API settings of Buffer](https://publish.buffer.com/settings/api).
3. Open "Settings > Connect to Buffer" and save the API key.
4. Choose the channels to send to. For a channel that should receive the vertical image, check "Vertical image with the title (1080×1920)".
5. Choose whether a post is saved as a draft in Buffer or added to the queue.
6. If you want, set the text before the title, the tags and the categories. To add a category, choose it from the list and press "Add". To take it off, press "Remove".
7. Save the settings, then send a test from the Test tab.

The settings screen has three tabs. "Settings" holds the settings, "Test" sends a test, and "Recent activity" shows the result of the last 20 deliveries.

== Frequently Asked Questions ==

= What do I need? =

WordPress 5.6 or later, PHP 7.4 or later, and a Buffer account with an API key. The vertical image also needs the GD extension of PHP with FreeType support.

= Can I keep the API key out of the database? =

Yes. Define it in `wp-config.php`. The constant takes priority over the key saved on the settings screen.

`define( 'WCTB_API_KEY', 'your API key' );`

= Will my post go out on social media as soon as I publish it? =

Not by default. The plugin saves it as a draft in Buffer, and it is published only after you schedule it there. If you choose "Add it to the queue", Buffer publishes it at the next slot of your posting schedule.

= How can I try the plugin without publishing a post? =

Use the Test tab. It sends one of your published posts to Buffer as a draft.

= A post was not sent. Why? =

Open the Test tab. Each post in the list shows whether it will be sent with the current settings, or why it will not. If it was sent and failed, the Recent activity tab shows the error message from Buffer.

= Why does the post in Buffer have no image? =

Buffer fetches the image from its URL. The image must be served over HTTPS, it must open without logging in, and your site must not block Buffer. See "Images" in the description.

= Why does my Instagram story have no caption? =

Instagram stories have no caption. A channel with "Vertical image with the title" receives the image as a story, and the title is drawn on the image instead.

= Which services are supported? =

Facebook and Instagram are tested. Other services work when Buffer needs no settings that are specific to the service. Google Business Profile and Pinterest need such settings and are not supported yet.

= Are pages and custom post types sent? =

No. Only posts of the "post" type are sent.

= Is a post sent again when I update it? =

No. A post is sent once, when it is published for the first time. If you move a published post back to a draft and publish it again, it is sent only to the channels where the first delivery failed.

== Changelog ==

= 0.4.2 =
* The text before the title is drawn in a slightly darker color on the vertical image, so that it does not look like a part of the title.

= 0.4.1 =
* A URL in the text before the title or in the title is no longer drawn on the vertical image.

= 0.4.0 =
* Added the text before the title. It is used in the text sent to Buffer and on the vertical image.

= 0.3.2 =
* Fixed the delivery of Instagram stories, which Buffer rejected.

= 0.3.1 =
* The settings screen is divided into three tabs: Settings, Test and Recent activity.

= 0.3.0 =
* Added the test from the settings screen. It sends a published post to Buffer as a draft.

= 0.2.0 =
* Added the filter by category. A category also covers every category below it.

= 0.1.0 =
* First version. Sends a post to Buffer when it is published, as a draft or into the queue, with the vertical image and the filter by tag.
