<?php
class WordPressFindOrphanImages {

	const DB_SERVER = 'localhost';
	const DB_USER = 'username';
	const DB_PASSWORD = 'password';
	const DB_DATABASE = 'database';
	const DB_TEMP_TABLE_NAME = 'tmpfindorphanimages';

	const PATH_TO_UPLOADS = '/docroot/path/to/wp-content/uploads/';
	const LOG_FILE_UNUSED_PATH = './imageunused.log';
	const LOG_FILE_UNUSED_BASH_PATH = './imageunusedmove.sh';
	const LOG_FILE_MISSING_PATH = './imagenotavailable.log';

	const ROW_FETCH_WRITE_SIZE = 50;


	public function __construct() {

		// validate settings
		if (substr(self::PATH_TO_UPLOADS,-1) != '/') {
			echo("Error: PATH_TO_UPLOADS must end with a trailing forward slash\n");
			exit();
		}

		// connect to database and create temp table
		$mySQLi = new mysqli(
			self::DB_SERVER,
			self::DB_USER,
			self::DB_PASSWORD,
			self::DB_DATABASE
		);

		$this->DBTempTableCreate($mySQLi);

		// work over database attachment meta rows and drop all image paths into MySQL temporary table
		foreach ($this->postAttachmentMetaIterator($mySQLi) as $postMetaItem) {
			$this->parseAttachmentMetaAddToDB($mySQLi,$postMetaItem);
		}

		// work over file system and drop all image paths into MySQL temporary table
		$this->scanFilesystemAddImagesToDB($mySQLi);

		// generate log/bash files from generated MySQL data
		$this->generateUnusedImageReport($mySQLi);
		$this->generateMissingImageReport($mySQLi);

		// drop temp table and close database connection
		$this->DBTempTableDrop($mySQLi);
		$mySQLi->close();

		echo("\nDone!\n\n");
	}

	private function DBTempTableCreate(mysqli $mySQLi) {

		$this->DBTempTableDrop($mySQLi);
		$mySQLi->query(
			'CREATE TABLE ' . self::DB_TEMP_TABLE_NAME . ' (' .
				'ID int(10) unsigned NOT NULL AUTO_INCREMENT,' .
				'type char(5) NOT NULL,' .
				'filepathMD5 char(32) NOT NULL,' .
				'filepath varchar(1000) NOT NULL,' .
				'PRIMARY KEY (ID),' .
				'KEY type (type),' .
				'KEY filepathMD5 (filepathMD5)' .
			')'
		);
	}

	private function DBTempTableDrop(mysqli $mySQLi) {

		$mySQLi->query('DROP TABLE IF EXISTS ' . self::DB_TEMP_TABLE_NAME);
	}

	private function postAttachmentMetaIterator(mysqli $mySQLi) {

		$lastSeenPostID = 0;
		$rowCount = 0;
		$postType = 'attachment';

		while ($lastSeenPostID !== false) {
			// prepare statement and execute
			$statement = $mySQLi->stmt_init();
			$statement->prepare(
				'SELECT wp_posts.ID,wp_postmeta.meta_value ' .
				'FROM wp_posts INNER JOIN wp_postmeta ON (wp_posts.ID = wp_postmeta.post_id) ' .
				'WHERE ' .
					'(wp_posts.ID > ?) AND ' .
					'(wp_posts.post_type = ?) AND ' .
					'(wp_postmeta.meta_key = \'_wp_attachment_metadata\') ' .
				'ORDER BY ID LIMIT ' . self::ROW_FETCH_WRITE_SIZE
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

			foreach ($resultRowList as $resultRow) {
				// unserialize meta_value and yield
				yield unserialize($resultRow['meta_value']);
				$lastSeenPostID = $resultRow['ID'];
				$rowCount++;
			}

			echo($rowCount . " attachments processed\n");
		}
	}

	private function parseAttachmentMetaAddToDB(mysqli $mySQLi,array $attachmentMetaData) {

		if (
			(isset($attachmentMetaData['file'])) &&
			($this->getIsFilenameImage($attachmentMetaData['file']))
		) {
			// meta data is about an image
			$rootFilename = $attachmentMetaData['file'];
			$basePath = dirname($rootFilename) . '/';
			$insertFilenameList = [$rootFilename];

			if (isset($attachmentMetaData['sizes'])) {
				// work over sizes
				foreach ($attachmentMetaData['sizes'] as $imageSizeData) {
					if (isset($imageSizeData['file'])) {
						$insertFilenameList[] = $basePath . $imageSizeData['file'];
					}
				}
			}

			// insert rows into database
			$this->processInsertFilenameListToDB($mySQLi,0,$insertFilenameList);
		}
	}

	private function scanFilesystemAddImagesToDB(mysqli $mySQLi) {

		echo(sprintf("\nScanning for WordPress image uploads at: %s\n",self::PATH_TO_UPLOADS));

		$insertFilenameList = [];
		$pathToUploadLength = strlen(self::PATH_TO_UPLOADS);

		foreach ($this->readFileSubDir(self::PATH_TO_UPLOADS) as $fileItem) {
			// remove the leading path to uploads directory from filepath
			$fileItem = substr($fileItem,$pathToUploadLength);

			// file must be under a YYYY/MM/ directory and actually an image
			if (
				(preg_match('/^[0-9]{4}\/[0-9]{2}/',$fileItem)) &&
				($this->getIsFilenameImage($fileItem))
			) {
				// add filename to stack
				$insertFilenameList[] = $fileItem;

				if (count($insertFilenameList) >= self::ROW_FETCH_WRITE_SIZE) {
					// insert rows into database
					$this->processInsertFilenameListToDB($mySQLi,1,$insertFilenameList);
					$insertFilenameList = [];
				}
			}
		}

		// insert any remaining rows
		if ($insertFilenameList) {
			$this->processInsertFilenameListToDB($mySQLi,1,$insertFilenameList);
		}
	}

	private function processInsertFilenameListToDB(mysqli $mySQLi,$type,array $filenameList) {

		$statement = $mySQLi->stmt_init();
		$statement->prepare('INSERT INTO ' . self::DB_TEMP_TABLE_NAME . ' (type,filepathMD5,filepath) VALUES (' . $type . ',?,?)');

		foreach ($filenameList as $item) {
			$filenameMD5 = md5($item);
			$statement->bind_param('ss',$filenameMD5,$item);
			$statement->execute();
		}

		$statement->close();
	}

	private function generateUnusedImageReport(mysqli $mySQLi) {

		$resultRowList = $this->executeSimpleSelectQuery(
			$mySQLi,
			'SELECT imagedisk.filepath ' .
			'FROM tmpfindorphanimages imagedisk ' .
			'LEFT JOIN tmpfindorphanimages imagewp ON (' .
				'(imagedisk.filepathMD5 = imagewp.filepathMD5) AND ' .
				'(imagewp.type = 0)' .
			') ' .
			'WHERE (imagedisk.type = 1) AND (imagewp.ID IS NULL)'
		);

		// open log files
		$fhLogFile = fopen(self::LOG_FILE_UNUSED_PATH,'w');
		$fhLogBashFile = fopen(self::LOG_FILE_UNUSED_BASH_PATH,'w');

		fwrite(
			$fhLogBashFile,
			"#!/bin/bash\n\n" .
			"SOURCE_IMAGE_DIR=\"" . self::PATH_TO_UPLOADS . "\"\n" .
			"DEST_UNUSED_IMAGE_DIR=\"/tmp/wp-unused/\"\n\n"
		);

		$seenSourceImagePathList = [];
		foreach ($resultRowList as $resultRowItem) {
			// add filename to unused image log file
			$filename = $resultRowItem[0];
			fwrite($fhLogFile,self::PATH_TO_UPLOADS . $filename . "\n");

			// add commands to unused image relocate bash script
			$filenameBaseDir = dirname($filename);
			if (!isset($seenSourceImagePathList[$filenameBaseDir])) {
				// create unseen directory path to hold moved files
				fwrite(
					$fhLogBashFile,
					"mkdir -p \"\${DEST_UNUSED_IMAGE_DIR}" . $filenameBaseDir . "\"\n"
				);

				$seenSourceImagePathList[$filenameBaseDir] = true;
			}

			// generate image file move command
			fwrite(
				$fhLogBashFile,
				"mv \"\${SOURCE_IMAGE_DIR}" . $filename . "\" \"\${DEST_UNUSED_IMAGE_DIR}" . $filenameBaseDir . "\"\n"
			);
		}

		// close log files
		fclose($fhLogBashFile);
		fclose($fhLogFile);
	}

	private function generateMissingImageReport(mysqli $mySQLi) {

		$resultRowList = $this->executeSimpleSelectQuery(
			$mySQLi,
			'SELECT imagewp.filepath ' .
			'FROM tmpfindorphanimages imagewp ' .
			'LEFT JOIN tmpfindorphanimages imagedisk ON (' .
				'(imagewp.filepathMD5 = imagedisk.filepathMD5) AND ' .
				'(imagedisk.type = 1)' .
			') ' .
			'WHERE (imagewp.type = 0) AND (imagedisk.ID IS NULL)'
		);

		// open log file
		$fhLogFile = fopen(self::LOG_FILE_MISSING_PATH,'w');

		foreach ($resultRowList as $resultRowItem) {
			// add filename to missing image log file
			fwrite($fhLogFile,self::PATH_TO_UPLOADS . $resultRowItem[0] . "\n");
		}

		// close log file
		fclose($fhLogFile);
	}

	private function executeSimpleSelectQuery(mysqli $mySQLi,$query) {

		$queryResult = $mySQLi->query($query);
		$resultRowList = $queryResult->fetch_all(MYSQLI_NUM);
		$queryResult->free();

		return $resultRowList;
	}

	private function readFileSubDir($scanDir) {

		$handle = opendir($scanDir);

		while (($fileItem = readdir($handle)) !== false) {
			// skip '.' and '..'
			if (($fileItem == '.') || ($fileItem == '..')) continue;
			$fileItem = rtrim($scanDir,'/') . '/' . $fileItem;

			// if dir found call again recursively
			if (is_dir($fileItem)) {
				foreach ($this->readFileSubDir($fileItem) as $childFileItem) {
					yield $childFileItem;
				}

			} else {
				yield $fileItem;
			}
		}

		closedir($handle);
	}

	private function getIsFilenameImage($filename) {

		$MIMETypeList = [
			'gif' => 'image/gif',
			'jpeg' => 'image/jpeg',
			'jpg' => 'image/jpeg',
			'png' => 'image/png'
		];

		// extract file extension and lowercase
		$fileExtension = explode('.',$filename);
		$fileExtension = strtolower(array_pop($fileExtension));

		// return true if file extension matches an image type
		return (isset($MIMETypeList[$fileExtension]));
	}
}


new WordPressFindOrphanImages();
