# WordPress recreate thumbnails

Script to regenerate or adjust all thumbnail sizes from original source images within a WordPress blog. Useful when sizes have changed due to a switch/update of theme or the modification of [`add_image_size()`](https://codex.wordpress.org/Function_Reference/add_image_size) setup.

Whilst there are a [number](https://wordpress.org/plugins/regenerate-thumbnails/) of [regenerate](https://wordpress.org/plugins/force-regenerate-thumbnails/) image utilities [already](https://wordpress.org/plugins/ajax-thumbnail-rebuild/) available, most if not all are implemented as WordPress plugins - thus their processing happens within a HTTP request slowing things down, timing out and/or taking a large amount of time to complete due to the need of incremental processing.

Script should be called from the PHP CLI and gets the job done as fast as your CPU allows - it was written for a WordPress blog containing well over 4GB of images where a plugin style utility wasn't really going to cut it. Having said that, it is rather manual and **can be destructive** - a reliable and tested backup and some decent knowledge of the WordPress image sizes system is strongly advisable.

In addition a second [find orphan images](#find-orphan-images) script can be executed after successful image regeneration, which seeks out images present under `/wp-content/uploads/[YEAR]/[MONTH]` that are no longer referenced and moves them outside of the `/wp-content/uploads/` path.

- [Requires](#requires)
- [Rebuild thumbnails](#rebuild-thumbnails)
	- [Usage](#usage)
- [Find orphan images](#find-orphan-images)
	- [Usage](#usage-1)

## Requires

- PHP 5.5.
- [GD](https://php.net/manual/en/book.image.php) for thumbnail image creation.
- [MySQLi](https://php.net/mysqli) with [MySQL native driver](https://php.net/manual/en/book.mysqlnd.php).

## Rebuild thumbnails

The `wordpressrebuildthumbnails.php` script performs the following tasks:

- Loads all `wp_posts` of type `attachment`.
- Extracts the origin image filename from the `guid` field, ensures image actually exists on disk and corrects `post_mime_type` for the image if required.
- Ensures attachment has both `_wp_attached_file` and `_wp_attachment_metadata` rows in the `wp_postmeta` table, creates if they do not exist.
- Validates serialized PHP data in `_wp_attachment_metadata` for origin image size and path, corrects if required.
- Works over existing thumbnail sizes for the image attachment, validates file existence, image dimensions and if the size is actually required - drops if required.
- Creates image sizes for new additions and/or sizes dropped in the previous step due to invalid data. Image resize dimensions are based on the same algorithms as used by WordPress (`wp_constrain_dimensions()` and `image_resize_dimensions()` found in `/wp-includes/media.php`) and like WordPress images that would be generated larger than the origin image will be skipped. Additionally recreated images will have a sharpen applied using the `imageconvolution()` GD function.
- Remove any orphan image sizes (e.g. `my_base_image_name-UNUSED_WIDTH-UNUSED_HEIGHT.jpg`) that are no longer required for the attachment from disk.
- Finally updates database `_wp_attachment_metadata` for the associated `wp_postmeta` row.

The script will only modify/resize images when required - if previous images meet the defined size requirements they will **not** be regenerated. This means the script can be re-run to validate all database data lines up with disk image files.

### Usage

Configure the constants at the top of [`wordpressrebuildthumbnails.php`](wordpressrebuildthumbnails.php) to suit. Details of each setting are as follows:

<table>
	<tr>
		<td>DB_SERVER</td>
		<td>Hostname/socket of the MySQL database. Typically would be <code>localhost</code>.</td>
	</tr>
	<tr>
		<td>DB_USER</td>
		<td>MySQL database login user.</td>
	</tr>
	<tr>
		<td>DB_PASSWORD</td>
		<td>MySQL database login password.</td>
	</tr>
	<tr>
		<td>DB_DATABASE</td>
		<td>MySQL database name containing WordPress blog tables.</td>
	</tr>
	<tr>
		<td>JPEG_IMAGE_QUALITY</td>
		<td>Save quality for JPEG image types between 0-100.</td>
	</tr>
	<tr>
		<td>PUBLIC_SITE_UPLOADS_URL</td>
		<td>Full public URL path to the <code>/wp-content/uploads/</code> folder, including trailing forward slash. This is used to construct the regular expression for extracting internal blog post image references.</td>
	</tr>
	<tr>
		<td>PATH_TO_UPLOADS</td>
		<td>Full/relative (to script) path containing all WordPress blog images, with trailing slash.</td>
	</tr>
	<tr>
		<td>LOG_FILE_PATH</td>
		<td>Log file name used to output any unrepairable errors during processing.</td>
	</tr>
</table>

Next, add your desired image sizes to the bottom of `wordpressrebuildthumbnails.php` as an array passed into the `WordPressRebuildThumbnails()` constructor. Each array provides the `width`, `height` and `crop` settings per size - matching the [`add_image_size()`](https://codex.wordpress.org/Function_Reference/add_image_size) WordPress method.

It would be wise to at least provide the WordPress default sizes of `thumbnail`, `medium` and `large`.

Example:

```php
new WordPressRebuildThumbnails([
	'thumbnail' => [300,275,true],
	'medium' => [610,610],
	'large' => [960,960]
]);
```

Now execute the script from the destination WordPress blog server/location via CLI and let it do its work.

```sh
$ php wordpressrebuildthumbnails.php
```

## Find orphan images

After you are happy with the thumbnail regeneration process, the [`wordpressfindorphanimages.php`](wordpressfindorphanimages.php) script can be used to determine the following:

- Any images located under `/wp-content/uploads/[YEAR]/[MONTH]` which are never referenced within any `_wp_attachment_metadata` database records.
- Images referenced in `_wp_attachment_metadata` which are missing from `/wp-content/uploads/` on disk.

### Usage

Similar configuration to the resize script [above](#usage), with the following additional settings:

<table>
	<tr>
		<td>LOG_FILE_UNUSED_PATH</td>
		<td>File to save a listing of all unused/unreferenced images.</td>
	</tr>
	<tr>
		<td>LOG_FILE_UNUSED_BASH_PATH</td>
		<td>Bash script to assist in the moving of all unused/unreferenced images from <code>/wp-content/uploads/</code> (see below for details).</td>
	</tr>
	<tr>
		<td>LOG_FILE_MISSING_PATH</td>
		<td>File to save a listing of all images defined in attachment database rows that could not be located on disk.</td>
	</tr>
</table>

Now execute the script from the destination WordPress blog server/location via CLI and let it do its work.

```sh
$ php wordpressfindorphanimages.php
```

An example of the generated `LOG_FILE_UNUSED_BASH_PATH` bash script is as follows, allowing for an easy moving out of unused images:

```sh
#!/bin/bash
SOURCE_IMAGE_DIR=/path/to/wp-uploads/
DEST_UNUSED_IMAGE_DIR=/tmp/wp-unused/update/to/suit/

mkdir -p ${DEST_UNUSED_IMAGE_DIR}2014/02
mv ${SOURCE_IMAGE_DIR}first-image.jpg ${DEST_UNUSED_IMAGE_DIR}2014/02
mv ${SOURCE_IMAGE_DIR}second-image.jpg ${DEST_UNUSED_IMAGE_DIR}2014/02
mv ${SOURCE_IMAGE_DIR}third-image.jpg ${DEST_UNUSED_IMAGE_DIR}2014/02
mkdir -p ${DEST_UNUSED_IMAGE_DIR}2014/03
mv ${SOURCE_IMAGE_DIR}fourth-image.jpg ${DEST_UNUSED_IMAGE_DIR}2014/03
mv ${SOURCE_IMAGE_DIR}fifth-image.jpg ${DEST_UNUSED_IMAGE_DIR}2014/03
mv ${SOURCE_IMAGE_DIR}sixth-image.jpg ${DEST_UNUSED_IMAGE_DIR}2014/03
```

`DEST_UNUSED_IMAGE_DIR` should be updated to suit before executing the bash script.
