<?php
class WordPressRebuildThumbnails {

	const DB_SERVER = 'localhost';
	const DB_USER = 'username';
	const DB_PASSWORD = 'password';
	const DB_DATABASE = 'database';

	const JPEG_IMAGE_QUALITY = 90;
	const PUBLIC_SITE_UPLOADS_URL = 'http://www.siteurl.com/wp-content/uploads/';
	const PATH_TO_UPLOADS = '/docroot/path/to/wp-content/uploads/';
	const LOG_FILE_PATH = './rebuildthumbnailserror.log';
	const POST_SOURCE_ROW_FETCH = 50;

	private $wordPressImageSizeList;


	public function __construct(array $imageSizeList) {

		// validate settings
		if (substr(self::PUBLIC_SITE_UPLOADS_URL,-1) != '/') {
			echo("Error: PUBLIC_SITE_UPLOADS_URL must end with a trailing forward slash\n");
			exit();
		}

		if (substr(self::PATH_TO_UPLOADS,-1) != '/') {
			echo("Error: PATH_TO_UPLOADS must end with a trailing forward slash\n");
			exit();
		}

		foreach ($imageSizeList as &$imageSizeItem) {
			// if not given, don't crop the target image
			if (!isset($imageSizeItem[2])) $imageSizeItem[2] = false;
		}

		$this->wordPressImageSizeList = $imageSizeList;

		// connect to database
		$mySQLi = new mysqli(
			self::DB_SERVER,
			self::DB_USER,
			self::DB_PASSWORD,
			self::DB_DATABASE
		);

		// open log file to report any errors
		$fhLogFile = fopen(self::LOG_FILE_PATH,'w');

		// work over post attachments
		foreach ($this->postAttachmentIterator($mySQLi) as $attachmentItem) {
			$attachmentID = $attachmentItem['ID'];

			// validate post attachment item row
			$attachmentItemData = $this->validateAttachmentItem($mySQLi,$fhLogFile,$attachmentItem);
			if ($attachmentItemData === false) {
				// skip item - either due to error (which will be logged to file) or not an image attachment type (harmless)
				continue;
			}

			// validate post attachment meta row(s)
			$validateAttachmentMetaRowsResult = $this->validateAttachmentMetaRows(
				$mySQLi,$fhLogFile,
				$attachmentID,
				$attachmentItemData['filename'],
				$attachmentItemData['MIMEType']
			);

			if ($validateAttachmentMetaRowsResult === false) {
				// invalid meta row count fetched - error logged, skipping image attachment processing
				continue;
			}

			list(
				$metaRowExists,
				$attachmentMetaData,
				$previousAttachmentMetaDataSerialized
			) = $validateAttachmentMetaRowsResult;

			// process attachment meta data (either existing, or blank)
			$attachmentMetaData = $this->parseAttachmentMetaData(
				$attachmentID,
				$attachmentItemData['filename'],
				$attachmentItemData['MIMEType'],
				$attachmentMetaData
			);

			// insert/update meta data for attachment in database only if there has been a modification in data
			$attachmentMetaDataSerialized = serialize($attachmentMetaData);
			if ($previousAttachmentMetaDataSerialized != $attachmentMetaDataSerialized) {
				// meta data has been modified, update database
				$this->updateDatabaseAttachmentMetaData(
					$mySQLi,$metaRowExists,
					$attachmentID,$attachmentMetaDataSerialized
				);
			}
		}

		// close log file and database connection
		fclose($fhLogFile);
		$mySQLi->close();

		echo("\nDone!\n\n");
	}

	private function postAttachmentIterator(mysqli $mySQLi) {

		$lastSeenPostID = 0;
		$rowCount = 0;
		$postType = 'attachment';

		while ($lastSeenPostID !== false) {
			// prepare statement and execute
			$statement = $mySQLi->stmt_init();
			$statement->prepare(
				'SELECT ID,guid,post_mime_type ' .
				'FROM wp_posts ' .
				'WHERE (ID > ?) AND (post_type = ?) ' .
				'ORDER BY ID LIMIT ' . self::POST_SOURCE_ROW_FETCH
			);

			$statement->bind_param('is',$lastSeenPostID,$postType);
			$statement->execute();

			// fetch result set
			$queryResult = $statement->get_result();
			$resultRowList = $queryResult->fetch_all(MYSQLI_ASSOC);

			// free result and close statement
			$queryResult->free();
			$statement->close();

			$lastSeenPostID = false; // if zero result rows will be kept false and iterator ends

			// yield results
			foreach ($resultRowList as $resultRow) {
				yield [
					'ID' => $resultRow['ID'],
					'GUID' => trim($resultRow['guid']),
					'MIMEType' => trim($resultRow['post_mime_type'])
				];

				$lastSeenPostID = $resultRow['ID'];
				$rowCount++;
			}

			echo($rowCount . " attachments processed\n");
		}
	}

	private function validateAttachmentItem(mysqli $mySQLi,$fhLogFile,array $attachmentItem) {

		$attachmentID = $attachmentItem['ID'];

		// strip public URL suffix from GUID
		$attachmentFilename = $attachmentItem['GUID'];
		$attachmentFilename = preg_replace(
			'/^' . preg_quote(self::PUBLIC_SITE_UPLOADS_URL,'/') . '/',
			'',$attachmentFilename
		);

		if ($attachmentFilename == $attachmentItem['GUID']) {
			// update didn't happen, guessing self::PUBLIC_SITE_UPLOADS_URL is wrong and/or row data - abort
			fwrite($fhLogFile,sprintf(
				"Unable to parse wp_posts attachment GUID, skipping row [%d] / [%s]\n",
				$attachmentID,
				$attachmentItem['GUID']
			));

			return false;
		}

		// only interested in image attachment types
		$attachmentMIMEType = $this->getImageMIMETypeFromFilename($attachmentFilename);
		if ($attachmentMIMEType === false) return false;

		// validate file exists on disk
		if (!is_file(self::PATH_TO_UPLOADS . $attachmentFilename)) {
			// not found - abort
			fwrite($fhLogFile,sprintf(
				"Unable to locate image file on disk, skipping row [%d] / [%s]\n",
				$attachmentID,
				self::PATH_TO_UPLOADS . $attachmentFilename
			));

			return false;
		}

		// verfiy image mime type in database matches that of file - correct if wrong
		if ($attachmentMIMEType != $attachmentItem['MIMEType']) {
			echo(sprintf(
				"Updating image MIME type of wp_posts row [%d] from [%s] to [%s]\n",
				$attachmentID,
				$attachmentItem['MIMEType'],$attachmentMIMEType
			));

			// execute update SQL query
			$statement = $mySQLi->stmt_init();
			$statement->prepare('UPDATE wp_posts SET post_mime_type = ? WHERE (ID = ?)');
			$statement->bind_param('si',$attachmentMIMEType,$attachmentID);
			$statement->execute();
			$statement->close();
		}

		// return extracted information needed
		return [
			'filename' => $attachmentFilename, // image filename - sans full path to wp-content/uploads/
			'MIMEType' => $attachmentMIMEType
		];
	}

	private function validateAttachmentMetaRows(mysqli $mySQLi,$fhLogFile,$postID,$imageFilename,$imageMIMEType) {

		// fetch all wp_postmeta rows for attachment, expecting to find EXACTLY two (_wp_attached_file/_wp_attachment_metadata)
		// if we find less, we can create (safe), if more - can't determine which ones to work with - abort image attachment processing
		$statement = $mySQLi->stmt_init();
		$statement->prepare(
			'SELECT meta_key,meta_value ' .
			'FROM wp_postmeta ' .
			'WHERE ' .
				'(post_id = ?) AND ' .
				'(meta_key IN(\'_wp_attached_file\',\'_wp_attachment_metadata\'))'
		);

		$statement->bind_param('i',$postID);
		$statement->execute();

		// fetch result set meta data
		$queryResult = $statement->get_result();
		$resultRowList = $queryResult->fetch_all(MYSQLI_ASSOC);

		// free result and close statement
		$queryResult->free();
		$statement->close();

		if (count($resultRowList) > 2) {
			// this is bad, should never be more than two rows - abort
			fwrite($fhLogFile,sprintf(
				"Invalid number of wp_postmeta rows returned, expected two (2) but found (%d), skipping wp_posts attachment row [%d]\n",
				count($resultRowList),
				$postID
			));

			return false;
		}

		// flip MySQL result rows into hash table structure
		$attachmentMeta = array_column($resultRowList,'meta_value','meta_key');

		// insert or update the meta data value for '_wp_attached_file' if required
		if (!isset($attachmentMeta['_wp_attached_file'])) {
			// insert new row for [_wp_attached_file]
			echo(sprintf(
				"Missing wp_postmeta row for [_wp_attached_file] against wp_posts row [%d], inserting new row with value of [%s]\n",
				$postID,$imageFilename
			));

			$statement = $mySQLi->stmt_init();
			$statement->prepare(
				'INSERT INTO wp_postmeta (post_id,meta_key,meta_value) ' .
				'VALUES (?,\'_wp_attached_file\',?)'
			);

			$statement->bind_param('is',$postID,$imageFilename);
			$statement->execute();
			$statement->close();

		} elseif ($attachmentMeta['_wp_attached_file'] != $imageFilename) {
			// update row for [_wp_attached_file], invalid filename value
			echo(sprintf(
				"Incorrect wp_postmeta row value for [_wp_attached_file] against wp_posts row [%d], was [%s] correcting with a value of [%s]\n",
				$postID,
				$attachmentMeta['_wp_attached_file'],$imageFilename
			));

			$statement = $mySQLi->stmt_init();
			$statement->prepare(
				'UPDATE wp_postmeta SET meta_value = ? ' .
				'WHERE (post_id = ?) AND (meta_key = \'_wp_attached_file\')'
			);

			$statement->bind_param('si',$imageFilename,$postID);
			$statement->execute();
			$statement->close();
		}

		// return the attachment image meta data struct (unserialize), or if not exists return a blank shell
		$metaRowExists = isset($attachmentMeta['_wp_attachment_metadata']);

		return [
			$metaRowExists,
			// meta data as array
			($metaRowExists)
				? unserialize($attachmentMeta['_wp_attachment_metadata'])
				: $this->getAttachmentMetaDataShell(),
			// meta data serialized
			($metaRowExists)
				? $attachmentMeta['_wp_attachment_metadata']
				: ''
		];
	}

	private function getAttachmentMetaDataShell() {

		return [
			'width' => 0,
			'height' => 0,
			'file' => '',
			'sizes' => [],
			'image_meta' => [
				'aperture' => 0,
				'camera' => '',
				'caption' => '',
				'copyright' => '',
				'created_timestamp' => 0,
				'credit' => '',
				'focal_length' => 0,
				'iso' => 0,
				'shutter_speed' => 0,
				'title' => ''
			]
		];
	}

	private function parseAttachmentMetaData($postID,$imageFilename,$imageMIMEType,array $attachmentMetaData) {

		$sourceImageAbsolutePath = self::PATH_TO_UPLOADS . $imageFilename;

		// create file/width/height/sizes keys if they are not found in meta data
		if (!isset($attachmentMetaData['file'])) $attachmentMetaData['file'] = '';
		if (!isset($attachmentMetaData['width'])) $attachmentMetaData['width'] = 0;
		if (!isset($attachmentMetaData['height'])) $attachmentMetaData['height'] = 0;
		if (!isset($attachmentMetaData['sizes'])) $attachmentMetaData['sizes'] = [];

		// validate source image data - file path
		if ($attachmentMetaData['file'] != $imageFilename) {
			echo(sprintf(
				"Incorrect/unset image meta data origin file path [%s] for wp_posts attachment row [%d], updating to [%s]\n",
				$attachmentMetaData['file'],
				$postID,
				$imageFilename
			));

			$attachmentMetaData['file'] = $imageFilename;
		}

		// validate source image data - image dimensions
		list($sourceImageWidth,$sourceImageHeight) = getimagesize($sourceImageAbsolutePath);

		if ($attachmentMetaData['width'] != $sourceImageWidth) {
			// real image width does not match meta data
			echo(sprintf(
				"Incorrect/unset source image width [%d] for wp_posts attachment row [%d], updating to [%d]\n",
				$attachmentMetaData['width'],
				$postID,
				$sourceImageWidth
			));

			$attachmentMetaData['width'] = $sourceImageWidth;
		}

		if ($attachmentMetaData['height'] != $sourceImageHeight) {
			// real image height does not match meta data
			echo(sprintf(
				"Incorrect/unset source image height [%d] for wp_posts attachment row [%d], updating to [%d]\n",
				$attachmentMetaData['height'],
				$postID,
				$sourceImageHeight
			));

			$attachmentMetaData['height'] = $sourceImageHeight;
		}

		// remove deprecated [hwstring_small] key if found
		// link: https://core.trac.wordpress.org/ticket/21518
		unset($attachmentMetaData['hwstring_small']);

		// work over existing meta image sizes defined and validate all
		$attachmentMetaData = $this->parseAttachmentMetaDataValidateExistingSizes(
			$postID,
			$sourceImageAbsolutePath,$imageMIMEType,
			$attachmentMetaData
		);

		// create any image sizes that do not exist for image
		$attachmentMetaData = $this->parseAttachmentMetaDataCreateMissingSizes(
			$postID,
			$sourceImageAbsolutePath,$imageMIMEType,
			$attachmentMetaData
		);

		// drop any orphan images on the file system
		$this->parseAttachmentMetaDataDeleteOrphanImageFiles($postID,$sourceImageAbsolutePath,$attachmentMetaData);

		// images are now created and meta data cleaned up, return updated meta data
		return $attachmentMetaData;
	}

	private function parseAttachmentMetaDataValidateExistingSizes($postID,$sourceImageAbsolutePath,$imageMIMEType,array $attachmentMetaData) {

		// split up the source image file absolute path into its components
		$sourceImageDirectoryBase = dirname($sourceImageAbsolutePath) . '/';
		$sourceImageFilename = basename($sourceImageAbsolutePath);
		$removeImageSizeList = [];

		foreach ($attachmentMetaData['sizes'] as $imageSizeKey => &$imageSizeItem) {
			// is size required? if not add to remove list
			if (!isset($this->wordPressImageSizeList[$imageSizeKey])) {
				echo(sprintf(
					"Removing unrequired image size [%s] from wp_posts attachment row meta data [%d]\n",
					$imageSizeKey,$postID
				));

				$removeImageSizeList[] = $imageSizeKey;
				continue;
			}

			// ensure both width and height exists for the image size in meta data
			if (!isset($imageSizeItem['width'],$imageSizeItem['height'])) {
				echo(sprintf(
					"Removing image file size missing width and/or height properties [%s] from wp_posts attachment row meta data [%d]\n",
					$imageSizeKey,$postID
				));

				$removeImageSizeList[] = $imageSizeKey;
				continue;
			}

			// if image size has same filename as that of the source (bad!) remove image size
			if (
				(isset($imageSizeItem['file'])) &&
				($sourceImageFilename == $imageSizeItem['file'])
			) {
				echo(sprintf(
					"Removing image file size [%s] which has same target filename as that of source image from wp_posts attachment row meta data [%d]\n",
					$imageSizeKey,$postID
				));

				$removeImageSizeList[] = $imageSizeKey;
				continue;
			}

			// validate image actually exists in filesystem for size
			if (
				(!isset($imageSizeItem['file'])) ||
				(!is_file($sourceImageDirectoryBase . $imageSizeItem['file']))
			) {
				echo(sprintf(
					"Removing non-existent image file size [%s] from wp_posts attachment row meta data [%d]\n",
					$imageSizeKey,$postID
				));

				$removeImageSizeList[] = $imageSizeKey;
				continue;
			}

			// verify image size actually matches what WordPress would create
			$targetImageSizeData = $this->wordPressImageSizeList[$imageSizeKey];
			$imageResizeDimensions = $this->getImageResizeDimensions(
				$attachmentMetaData['width'],$attachmentMetaData['height'],
				$targetImageSizeData[0],$targetImageSizeData[1],$targetImageSizeData[2]
			);

			if ($imageResizeDimensions === false) {
				echo(sprintf(
					"Removing image file size [%s] since target image would be the same or greater in dimensions from wp_posts attachment row meta data [%d]\n",
					$imageSizeKey,$postID
				));

				$removeImageSizeList[] = $imageSizeKey;
				continue;
			}

			if (
				($imageSizeItem['width'] != $imageResizeDimensions[2]) ||
				($imageSizeItem['height'] != $imageResizeDimensions[3])
			) {
				echo(sprintf(
					"Removing image file size [%s] since dimensions do not match defined sizing target from wp_posts attachment row meta data [%d]\n",
					$imageSizeKey,$postID
				));

				$removeImageSizeList[] = $imageSizeKey;
				continue;
			}

			// verify image size on disk matches that of meta data
			list($imageSizeFileWidth,$imageSizeFileHeight) = getimagesize($sourceImageDirectoryBase . $imageSizeItem['file']);
			if (
				($imageSizeFileWidth != $imageSizeItem['width']) ||
				($imageSizeFileHeight != $imageSizeItem['height'])
			) {
				echo(sprintf(
					"Removing image file size [%s] since disk image dimensions do not match defined sizing target from wp_posts attachment row meta data [%d]\n",
					$imageSizeKey,$postID
				));

				$removeImageSizeList[] = $imageSizeKey;
				continue;
			}

			// ensure image size filename matches the correct naming format
			if (
				(isset($imageSizeItem['file'])) &&
				($this->getImageSizeFilename(
					$sourceImageFilename,
					$imageSizeItem['width'],$imageSizeItem['height']) != $imageSizeItem['file']
				)
			) {
				echo(sprintf(
					"Removing image file size [%s] since target filename does not match file naming conventions from wp_posts attachment row meta data [%d] / [%s]\n",
					$imageSizeKey,$postID,
					$imageSizeItem['file']
				));

				$removeImageSizeList[] = $imageSizeKey;
				continue;
			}

			// add/correct mime type to image size if not defined or wrong
			if (
				(!isset($imageSizeItem['mime-type'])) ||
				($imageSizeItem['mime-type'] != $imageMIMEType)
			) {
				echo(sprintf(
					"Incorrect/unset MIME type for image size [%s] for wp_posts attachment row meta data [%d], updating to [%s]\n",
					$imageSizeKey,$postID,
					$imageMIMEType
				));

				$imageSizeItem['mime-type'] = $imageMIMEType;
			}

			// if we have reached this point, existing image size has passed all tests
		}

		// now out of foreach loop, dump any unrequired image sizes from structure
		foreach ($removeImageSizeList as $removeSizeKey) {
			unset($attachmentMetaData['sizes'][$removeSizeKey]);
		}

		return $attachmentMetaData;
	}

	private function parseAttachmentMetaDataCreateMissingSizes($postID,$sourceImageAbsolutePath,$imageMIMEType,array $attachmentMetaData) {

		foreach ($this->wordPressImageSizeList as $imageSizeKey => $imageSizeItem) {
			// is size already present? if so skip
			if (isset($attachmentMetaData['sizes'][$imageSizeKey])) continue;

			// generate target image dimensions for size
			$imageResizeDimensions = $this->getImageResizeDimensions(
				$attachmentMetaData['width'],$attachmentMetaData['height'],
				$imageSizeItem[0],$imageSizeItem[1],$imageSizeItem[2]
			);

			if ($imageResizeDimensions === false) {
				// target image would be the same or greater in dimensions as source - skip creation
				continue;
			}

			$createdImageFilename = $this->getImageSizeFilename(
				basename($sourceImageAbsolutePath),
				$imageResizeDimensions[2],$imageResizeDimensions[3]
			);

			// add new size to attachment meta data
			$attachmentMetaData['sizes'][$imageSizeKey] = [
				'file' => $createdImageFilename,
				'width' => $imageResizeDimensions[2],
				'height' => $imageResizeDimensions[3],
				'mime-type' => $imageMIMEType
			];

			// create image, write to disk and log message to screen
			$targetImageFilename = dirname($sourceImageAbsolutePath) . '/' . $createdImageFilename;
			$this->createResizedImage($sourceImageAbsolutePath,$targetImageFilename,$imageResizeDimensions);

			echo(sprintf(
				"Created image for size [%s] as [%s] against wp_posts attachment row [%d]\n",
				$imageSizeKey,$targetImageFilename,$postID
			));
		}

		return $attachmentMetaData;
	}

	private function parseAttachmentMetaDataDeleteOrphanImageFiles($postID,$sourceImageAbsolutePath,array $attachmentMetaData) {

		// build list of image files we need to keep from generated attachment meta data
		$validImageFilenameList = [];
		foreach ($attachmentMetaData['sizes'] as $sizeItem) $validImageFilenameList[$sizeItem['file']] = true;

		// glob file system for all images in our search range
		$imageFilenameGlobCheckList = glob(preg_replace(
			'/(?i)(\.[a-z]{3,4})$/','*$1',
			$sourceImageAbsolutePath
		));

		// build the image delete regular expression, if a file matches this pattern and not found in $validImageFilenameList it's gone
		$imageDeleteTestRegExp = preg_quote($sourceImageAbsolutePath,'/');
		$imageDeleteTestRegExp = preg_replace(
			'/(?i)(\\\.[a-z]{3,4})$/','-[0-9]+x[0-9]+$1',
			$imageDeleteTestRegExp
		);

		$imageDeleteTestRegExp = '/^' . $imageDeleteTestRegExp . '$/';

		// now run over the glob list and check each image found
		foreach ($imageFilenameGlobCheckList as $imageFilenameCheck) {
			if (isset($validImageFilenameList[basename($imageFilenameCheck)])) {
				// this image is valid for our sizes - no delete
				continue;
			}

			if (preg_match($imageDeleteTestRegExp,$imageFilenameCheck)) {
				// we have a match, this file can be deleted
				unlink($imageFilenameCheck);

				echo(sprintf(
					"Removing orphan image file [%s] from disk, against wp_posts attachment row meta data [%d]\n",
					$imageFilenameCheck,$postID
				));
			}
		}
	}

	private function updateDatabaseAttachmentMetaData(mysqli $mySQLi,$isUpdate,$postID,$attachmentMetaDataSerialized) {

		if ($isUpdate) {
			// update existing postmeta row for attachment
			$statement = $mySQLi->stmt_init();
			$statement->prepare(
				'UPDATE wp_postmeta SET meta_value = ? ' .
				'WHERE (post_id = ?) AND (meta_key = \'_wp_attachment_metadata\')'
			);

			$statement->bind_param('si',$attachmentMetaDataSerialized,$postID);
			$statement->execute();
			$statement->close();

			echo(sprintf("Updated wp_postmeta row for [_wp_attached_file] against wp_posts row [%d]\n",$postID));

		} else {
			// insert a new postmeta row
			$statement = $mySQLi->stmt_init();
			$statement->prepare(
				'INSERT INTO wp_postmeta (post_id,meta_key,meta_value) ' .
				'VALUES (?,\'_wp_attachment_metadata\',?)'
			);

			$statement->bind_param('is',$postID,$attachmentMetaDataSerialized);
			$statement->execute();
			$statement->close();

			echo(sprintf("Inserted new wp_postmeta row for [_wp_attached_file] against wp_posts row [%d]\n",$postID));
		}
	}

	private function getImageResizeDimensions($sourceWidth,$sourceHeight,$targetWidth,$targetHeight,$crop) {

		if (($sourceWidth <= 0) || ($sourceHeight <= 0)) return false;
		if (($targetWidth <= 0) && ($targetHeight <= 0)) return false;

		if ($crop) {
			$targetAspectRatio = $sourceWidth / $sourceHeight;
			$targetWidth = min($targetWidth,$sourceWidth);
			$targetHeight = min($targetHeight,$sourceHeight);

			if (!$targetWidth) $targetWidth = intval($targetHeight * $targetAspectRatio);
			if (!$targetHeight) $targetHeight = intval($targetWidth / $targetAspectRatio);
			$sizeRatio = max($targetWidth / $sourceWidth,$targetHeight / $sourceHeight);

			$copyWidth = round($targetWidth / $sizeRatio);
			$copyHeight = round($targetHeight / $sizeRatio);

			$copyPointX = floor(($sourceWidth - $copyWidth) / 2);
			$copyPointY = floor(($sourceHeight - $copyHeight) / 2);

		} else {
			$copyWidth = $sourceWidth;
			$copyHeight = $sourceHeight;
			$copyPointX = $copyPointY = 0;

			if (!$targetWidth && !$targetHeight) {
				$targetWidth = $sourceWidth;
				$targetHeight = $sourceHeight;

			} else {
				$widthRatio = $heightRatio = 1.0;
				$widthRatioChange = $heightRatioChange = false;

				if (($targetWidth > 0) && ($sourceWidth > $targetWidth)) {
					// change width ratio
					$widthRatio = $targetWidth / $sourceWidth;
					$widthRatioChange = true;
				}

				if (($targetHeight > 0) && ($sourceHeight > $targetHeight)) {
					// change height ratio
					$heightRatio = $targetHeight / $sourceHeight;
					$heightRatioChange = true;
				}

				// calc target ratio either small or large
				$targetRatio = max($widthRatio,$heightRatio);

				if (
					(intval($sourceWidth * $targetRatio) > $targetWidth) ||
					(intval($sourceHeight * $targetRatio) > $targetHeight)
				) {
			 		// larger ratio too big, would result in dimension overflow
					$targetRatio = min($widthRatio,$heightRatio);
				}

				// very small dimensions may result in zero width/height - adjust if needed
				$finalWidth = max(1,intval($sourceWidth * $targetRatio));
				$finalHeight = max(1,intval($sourceHeight * $targetRatio));

				// account for rounding
				if ($widthRatioChange && ($finalWidth == ($targetWidth - 1))) $finalWidth = $targetWidth;
				if ($heightRatioChange && ($finalHeight == ($targetHeight - 1))) $finalHeight = $targetHeight;

				$targetWidth = $finalWidth;
				$targetHeight = $finalHeight;
			}
		}

		if (($targetWidth >= $sourceWidth) && ($targetHeight >= $sourceHeight)) {
			// image resize is pointless
			return false;
		}

		return [
			intval($copyPointX),intval($copyPointY),
			$targetWidth,$targetHeight,
			intval($copyWidth),intval($copyHeight)
		];
	}

	private function createResizedImage($sourceImagePath,$targetImagePath,array $resizeDimensions) {

		// get source image type and create source GD image instance
		list(,,$imageType) = getimagesize($sourceImagePath);
		switch ($imageType) {
			case IMAGETYPE_GIF:
				$GDSource = imagecreatefromgif($sourceImagePath);
				break;

			case IMAGETYPE_JPEG:
				$GDSource = imagecreatefromjpeg($sourceImagePath);
				break;

			default: // must be a PNG
				$GDSource = imagecreatefrompng($sourceImagePath);
		}

		// create new target GD instance
		$GDTarget = imagecreatetruecolor($resizeDimensions[2],$resizeDimensions[3]);

		// copy source resized onto target
		imagecopyresampled(
			$GDTarget,$GDSource,0,0, // target draw point
			$resizeDimensions[0],$resizeDimensions[1], // source copy point
			$resizeDimensions[2],$resizeDimensions[3], // target draw width/height
			$resizeDimensions[4],$resizeDimensions[5] // source copy width/height
		);

		// sharpen target
		$sharpenMatrix = [
			[-1.2,-1,-1.2],
			[-1,20,-1],
			[-1.2,-1,-1.2]
		];

		imageconvolution(
			$GDTarget,$sharpenMatrix,
			11.2,0 // note: array_sum(array_map('array_sum',$sharpenMatrix)) = 11.2;
		);

		// save image out to temp file the move into place
		$tempFilename = './tempimage.' . md5(uniqid());
		switch ($imageType) {
			case IMAGETYPE_GIF:
				imagegif($GDTarget,$tempFilename);
				break;

			case IMAGETYPE_JPEG:
				imagejpeg($GDTarget,$tempFilename,self::JPEG_IMAGE_QUALITY);
				break;

			default: // must be a PNG
				imagepng($GDTarget,$tempFilename);
		}

		rename($tempFilename,$targetImagePath);

		// free GD image instances
		imagedestroy($GDSource);
		imagedestroy($GDTarget);
	}

	private function getImageMIMETypeFromFilename($filename) {

		$MIMETypeList = [
			'gif' => 'image/gif',
			'jpeg' => 'image/jpeg',
			'jpg' => 'image/jpeg',
			'png' => 'image/png'
		];

		// extract file extension and lowercase
		$fileExtension = explode('.',$filename);
		$fileExtension = strtolower(array_pop($fileExtension));

		// return MIME type, false if unable to determine/not an image
		return (isset($MIMETypeList[$fileExtension]))
			? $MIMETypeList[$fileExtension]
			: false;
	}

	private function getImageSizeFilename($sourceImageFilename,$imageWidth,$imageHeight) {

		return preg_replace(
			'/(?i)(\.[a-z]{3,4})$/',
			sprintf('-%dx%d$1',$imageWidth,$imageHeight),
			$sourceImageFilename
		);
	}
}


new WordPressRebuildThumbnails([
	'thumbnail' => [300,275,true],
	'medium' => [610,610],
	'large' => [960,960]
]);
