<?php
require_once(realpath(dirname(__FILE__) . '/DatabaseManager.php'));
require_once(realpath(dirname(__FILE__) . '/ScreenshotObject.php'));

class ScreenshotManager {
	private static $objectCacheTime = 3600; //1 hour
	private static $userScreenshotsCacheTime = 180;
	private static $buildScreenshotsCacheTime = 3600;
	private static $addonScreenshotsCacheTime = 3600;

	public static $maxFileSize = 3000000; //3MB

	public static $thumbWidth = 128;
	public static $thumbHeight = 128;

	public static function getFromID($id, $resource = false) {

		if($resource !== false) {
			$ScreenshotObject = new ScreenshotObject($resource);
		} else {
			$database = new DatabaseManager();
			ScreenshotManager::verifyTable($database);
			$resource = $database->query("SELECT * FROM `screenshots` WHERE `id` = '" . $database->sanitize($id) . "' LIMIT 1");

			if(!$resource) {
				throw new Exception("Database error: " . $database->error());
			}

			if($resource->num_rows == 0) {
				$ScreenshotObject = false;
			} else {
				$ScreenshotObject = new ScreenshotObject($resource->fetch_object());
			}
			$resource->close();
		}

		return $ScreenshotObject;
	}

	public static function getScreenshotsFromBLID($id) {

		$database = new DatabaseManager();
		ScreenshotManager::verifyTable($database);
		$resource = $database->query("SELECT * FROM `screenshots` WHERE `blid` = '" . $database->sanitize($id) . "'");

		if(!$resource) {
			throw new Exception("Database error: " . $database->error());
		}
		$userScreenshots = [];

		while($row = $resource->fetch_object()) {
			$userScreenshots[] = ScreenshotManager::getFromID($row->id, $row)->getID();
		}
		$resource->close();

		return $userScreenshots;
	}

	public static function getScreenshotsFromBuild($id) {

		$database = new DatabaseManager();
		ScreenshotManager::verifyTable($database);
		$resource = $database->query("SELECT `sid` FROM `build_screenshotmap` WHERE `bid` = '" . $database->sanitize($id) . "'");

		if(!$resource) {
			throw new Exception("Database error: " . $database->error());
		}
		$buildScreenshots = [];

		while($row = $resource->fetch_object()) {
			$buildScreenshots[] = $row->sid;
		}
		$resource->close();

		return $buildScreenshots;
	}

	public static function getScreenshotsFromAddon($id) {
		$database = new DatabaseManager();
		ScreenshotManager::verifyTable($database);
		$resource = $database->query("SELECT `sid` FROM `addon_screenshotmap` WHERE `aid` = '" . $database->sanitize($id) . "'");

		if(!$resource) {
			throw new Exception("Database error: " . $database->error());
		}
		$addonScreenshots = [];

		while($row = $resource->fetch_object()) {
			$addonScreenshots[] = $row->sid;
		}
		$resource->close();

		return $addonScreenshots;
	}

	public static function uploadScreenshotForAddon($addon, $ext, $tempPath) {
		$blid = $addon->getManagerBLID();
		$tempThumb = ScreenshotManager::createTempThumbnail($tempPath, $ext);
		$database = new DatabaseManager();
		ScreenshotManager::verifyTable($database);

		list($width, $height) = getimagesize($tempPath);

		if(!$database->query("INSERT INTO `screenshots` (`blid`, `x`, `y`) VALUES ('" .
			$database->sanitize($blid) . "'," .
			"'" . $width . "','" . $height . "')")) {
			throw new Exception("Database error: " . $database->error());
		}

		$sid = $database->fetchMysqli()->insert_id;
		require_once(realpath(dirname(__FILE__) . '/AWSFileManager.php'));

		AWSFileManager::uploadNewScreenshot($sid, "screenshot." . $ext, $tempPath, $tempThumb);

		return ScreenshotManager::addScreenshotToAddon($sid, $addon->getID());
	}

	public static function addScreenshotToAddon($sid, $bid) {
		$database = new DatabaseManager();
		ScreenshotManager::verifyTable($database);
		$resource = $database->query("SELECT 1 FROM `addon_screenshotmap` WHERE
			`sid` = '" . $database->sanitize($sid) . "' AND
			`aid` = '" . $database->sanitize($bid) . "' LIMIT 1");

		if(!$resource) {
			throw new Exception("Database error: " . $database->error());
		}

		if($resource->num_rows > 0 ) {
			$resource->close();
			return false;
		}
		$resource->close();

		if(!$database->query("INSERT INTO `addon_screenshotmap` (sid, aid) VALUES ('" .
			$database->sanitize($sid) . "', '" .
			$database->sanitize($bid) . "')")) {
			throw new Exception("Failed to create new build screenshot entry: " . $database->error());
		}
		return true;
	}

	public static function deleteScreenshot($sid) {
		$db = new DatabaseManager();
		$db->query("DELETE FROM `screenshots` WHERE `id`='" . $db->sanitize($sid) . "'");
	}

	public static function uploadScreenshotForBuildID($bid, $tempPath) {
		$build = BuildManager::getFromID($bid);

		if($build === false) {
			return false;
		}
		return ScreenshotManager::uploadScreenshotForBuild($build, $tempPath);
	}

	public static function uploadScreenshotForBuild($build, $ext, $tempPath) {
		$blid = $build->getBLID();
		$tempThumb = ScreenshotManager::createTempThumbnail($tempPath, $ext);
		$database = new DatabaseManager();
		ScreenshotManager::verifyTable($database);
		if(!$database->query("INSERT INTO `screenshots` (`blid`) VALUES ('" .
			$database->sanitize($blid) . "')")) {
			throw new Exception("Database error: " . $database->error());
		}
		$sid = $database->fetchMysqli()->insert_id;
		require_once(realpath(dirname(__FILE__) . '/AWSFileManager.php'));
		AWSFileManager::uploadNewScreenshot($sid, "screenshot." . $ext, $tempPath, $tempThumb);

		if(ScreenshotManager::buildHasPrimaryScreenshot($build->getID())) {
			return ScreenshotManager::addScreenshotToBuild($sid, $build->getID());
		} else {
			return ScreenshotManager::setBuildPrimaryScreenshot($sid, $build->getID());
		}
	}

	public static function buildHasPrimaryScreenshot($bid) {
		$database = new DatabaseManager();
		ScreenshotManager::verifyTable($database);
		$resource = $database->query("SELECT 1 FROM `build_screenshotmap` WHERE
			`bid` = '" . $database->sanitize($bid) . "' AND
			`primary` = 1 LIMIT 1");

		if(!$resource) {
			throw new Exception("Database error: " . $database->error());
		}

		if($resource->num_rows > 0 ) {
			$resource->close();
			return true;
		}
		$resource->close();
		return false;
	}

	public static function getBuildPrimaryScreenshot($bid) {

		$database = new DatabaseManager();
		ScreenshotManager::verifyTable($database);
		$resource = $database->query("SELECT `sid` FROM `build_screenshotmap` WHERE
			`bid` = '" . $database->sanitize($bid) . "' AND
			`primary` = 1 LIMIT 1");

		if(!$resource) {
			throw new Exception("Database error: " . $database->error());
		}

		if($resource->num_rows == 0 ) {
			$resource->close();
			return false;
		}
		$row = $resource->fetch_object();
		$sid = ScreenshotManager::getFromID($row->sid);
		$resource->close();

		return $sid;
	}

	public static function addScreenshotToBuild($sid, $bid) {
		$database = new DatabaseManager();
		ScreenshotManager::verifyTable($database);
		$resource = $database->query("SELECT 1 FROM `build_screenshotmap` WHERE
			`sid` = '" . $database->sanitize($sid) . "' AND
			`bid` = '" . $database->sanitize($bid) . "' LIMIT 1");

		if(!$resource) {
			throw new Exception("Database error: " . $database->error());
		}

		if($resource->num_rows > 0 ) {
			$resource->close();
			return false;
		}
		$resource->close();

		if(!$database->query("INSERT INTO `build_screenshotmap` (sid, bid) VALUES ('" .
			$database->sanitize($sid) . "', '" .
			$database->sanitize($bid) . "')")) {
			throw new Exception("Failed to create new build screenshot entry: " . $database->error());
		}
		return true;
	}

	public static function setBuildPrimaryScreenshot($sid, $bid) {
		//we don't care if this returns true or false, this is to ensure the mapping exists
		ScreenshotManager::addScreenshotToBuild($sid, $bid);
		$database = new DatabaseManager();
		ScreenshotManager::verifyTable($database);
		if(!$database->query("UPDATE `build_screenshotmap` SET `primary` = '0' WHERE
			`bid` = '" . $database->sanitize($bid) . "'")) {
			throw new Exception("Database error: " . $database->error());
		}

		if(!$database->query("UPDATE `build_screenshotmap` SET `primary` = '1' WHERE
			`bid` = '" . $database->sanitize($bid) . "' AND
			`sid` = '" . $database->sanitize($sid) . "'")) {
			throw new Exception("Database error: " . $database->error());
		}
		return true;
	}

	private static function createTempThumbnail($tempFile, $ext) {
		//create thumbnail
		//requires GD2 to be installed
		//http://www.icant.co.uk/articles/phpthumbnails/
		if($ext == "png") {
			$img = imagecreatefrompng($tempFile);
		} else {
			$img = imagecreatefromjpeg($tempFile);
		}
		$oldx = imageSX($img);
		$oldy = imageSY($img);
		if($oldx > $oldy) {
			$thumb_w = ScreenshotManager::$thumbWidth;
			$thumb_h = $oldy * (ScreenshotManager::$thumbHeight / $oldx);
		}
		if($oldx < $oldy) {
			$thumb_w = $oldx * (ScreenshotManager::$thumbWidth / $oldy);
			$thumb_h = ScreenshotManager::$thumbHeight;
		}
		if($oldx == $oldy) {
			$thumb_w = ScreenshotManager::$thumbWidth;
			$thumb_h = ScreenshotManager::$thumbHeight;
		}
		$newimg = ImageCreateTrueColor($thumb_w, $thumb_h);
		imagecopyresampled($newimg, $img, 0, 0, 0, 0, $thumb_w, $thumb_h, $oldx, $oldy);
		$tempThumb = tempnam(sys_get_temp_dir(), "thb");
		imagepng($newimg, $tempThumb);
		imagedestroy($newimg);
		imagedestroy($img);
		return $tempThumb;
	}

	public static function verifyTable($database) {
		require_once(realpath(dirname(__FILE__) . '/UserManager.php'));
		require_once(realpath(dirname(__FILE__) . '/AddonManager.php'));
		require_once(realpath(dirname(__FILE__) . '/BuildManager.php'));
		UserManager::verifyTable($database); //we need users table to exist before we can create this one
		AddonManager::verifyTable($database);
		BuildManager::verifyTable($database);

		//we need to be able to build a url out of this data too
		//	UNIQUE KEY (`filename`),
		if(!$database->query("CREATE TABLE IF NOT EXISTS `screenshots` (
			`id` INT NOT NULL AUTO_INCREMENT,
			`blid` INT NOT NULL,
			`x` INT NOT NULL,
			`y` INT NOT NULL,
			`name` VARCHAR(60),
			`filename` VARCHAR(60),
			`description` TEXT,
			FOREIGN KEY (`blid`)
				REFERENCES users(`blid`)
				ON UPDATE CASCADE
				ON DELETE CASCADE,
			KEY (`name`),
			PRIMARY KEY (`id`))")) {
			throw new Exception("Error creating screenshots table: " . $database->error());
		}

		if(!$database->query("CREATE TABLE IF NOT EXISTS `build_screenshotmap` (
			`id` INT NOT NULL AUTO_INCREMENT,
			`sid` INT NOT NULL,
			`bid` INT NOT NULL,
			`primary` TINYINT NOT NULL DEFAULT 0,
			FOREIGN KEY (`sid`)
				REFERENCES screenshots(`id`)
				ON UPDATE CASCADE
				ON DELETE CASCADE,
			FOREIGN KEY (`bid`)
				REFERENCES build_builds(`id`)
				ON UPDATE CASCADE
				ON DELETE CASCADE,
			PRIMARY KEY (`id`))")) {
			throw new Exception("Error creating build_screenshotmap table: " . $database->error());
		}

		if(!$database->query("CREATE TABLE IF NOT EXISTS `addon_screenshotmap` (
			`id` INT NOT NULL AUTO_INCREMENT,
			`sid` INT NOT NULL,
			`aid` INT NOT NULL,
			FOREIGN KEY (`sid`)
				REFERENCES screenshots(`id`)
				ON UPDATE CASCADE
				ON DELETE CASCADE,
			FOREIGN KEY (`aid`)
				REFERENCES addon_addons(`id`)
				ON UPDATE CASCADE
				ON DELETE CASCADE,
			PRIMARY KEY (`id`))")) {
			throw new Exception("Error creating addon_screenshotmap table: " . $database->error());
		}
	}
}
?>
