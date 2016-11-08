<?php
require_once dirname(__FILE__) . '/DatabaseManager.php';
require_once dirname(__FILE__) . '/CommentObject.php';

class CommentManager {
	private static $userCacheTime = 600;
	private static $addonCacheTime = 600;
	private static $objectCacheTime = 3600;

	public static $SORTDATEASC = 0;
	public static $SORTDATEDESC = 1;

	public static function submitComment($aid, $blid, $comment) {
		$db = new DatabaseManager();
		$db->query("INSERT INTO `addon_comments` (`aid`, `blid`, `comment`) VALUES ('" . $db->sanitize($aid) . "', '" . $db->sanitize($blid) ."', '" . $db->sanitize($comment) . "');");
	}

	public static function getFromID($id, $resource = false) {

		if($resource !== false) {
			$commentObject = new CommentObject($resource);
		} else {
			$database = new DatabaseManager();
			CommentManager::verifyTable($database);
			$resource = $database->query("SELECT * FROM `addon_comments` WHERE `id` = '" . $database->sanitize($id) . "'");

			if(!$resource) {
				throw new Exception("Database error: " . $database->error());
			}

			if($resource->num_rows == 0) {
				$commentObject = false;
			}
			$commentObject = new CommentObject($resource->fetch_object());
			$resource->close();
		}

		return $commentObject;
	}

	//returns an array of comment ids in order as specified
	public static function getCommentIDsFromBLID($blid, $offset = 0, $limit = 10, $sort = 1) {
		$cacheString = serialize([
			"blid" => $aid,
			"offset" => $offset,
			"limit" => $limit,
			"sort" => $sort
		]);

		$database = new DatabaseManager();
		CommentManager::verifyTable($database);
		$baseQuery = "SELECT * FROM `addon_comments` WHERE `blid` = '" . $database->sanitize($blid) . "' ORDER BY `timestamp` ";

		if($sort == CommentManager::$SORTDATEASC) {
			$sortQuery = "ASC ";
		} else {
			$sortQuery = "DESC ";
		}
		$extQuery = "LIMIT '" . $database->sanitize($offset) . "', '" . $database->sanitize($limit) . "'";
		$resource = $database->query($baseQuery . $sortQuery . $extQuery);

		if(!$resource) {
			throw new Exception("Database error: " . $database->error());
		}
		$userComments = [];

		while($row = $resource->fetch_object()) {
			//this is mostly to get the data cached for the inevitable call to getFromID
			$userComments[] = CommentManager::getFromID($row->id, $row)->getID();
		}
		$resource->close();

		return $userComments;
	}

	public static function getCommentIDsFromAddon($aid, $offset = 0, $limit = 15, $sort = 0) {
		$cacheString = serialize([
			"aid" => $aid,
			"offset" => $offset,
			"limit" => $limit,
			"sort" => $sort
		]);

		$database = new DatabaseManager();
		CommentManager::verifyTable($database);
		$query = "SELECT * FROM `addon_comments` WHERE `aid` = '" . $database->sanitize($aid) . "' ORDER BY ";

		switch($sort) {
			case CommentManager::$SORTDATEASC:
				$query .= "`timestamp` ASC";
				break;
			case CommentManager::$SORTDATEDESC:
				$query .= "`timestamp` DESC";
				break;
			default:
				$query .= "`timestamp` ASC";
		}
		$query .=  " LIMIT " . $database->sanitize($offset) . ", " . $database->sanitize($limit);
		$resource = $database->query($query);

		if(!$resource) {
			throw new Exception("Database error: " . $database->error());
		}
		$addonComments = [];

		while($row = $resource->fetch_object()) {
			$addonComments[] = CommentManager::getFromID($row->id, $row)->getID();
		}
		$resource->close();

		return $addonComments;
	}

	public static function verifyTable($database) {
		require_once(realpath(dirname(__FILE__) . '/UserManager.php'));
		require_once(realpath(dirname(__FILE__) . '/AddonManager.php'));
		UserManager::verifyTable($database);
		AddonManager::verifyTable($database);

		if(!$database->query("CREATE TABLE IF NOT EXISTS `addon_comments` (
			`id` INT NOT NULL AUTO_INCREMENT,
			`blid` INT NOT NULL,
			`aid` INT NOT NULL,
			`comment` TEXT NOT NULL,
			`timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			`lastedit` TIMESTAMP NULL,
			KEY (`timestamp`),
			FOREIGN KEY (`aid`)
				REFERENCES addon_addons(`id`)
				ON UPDATE CASCADE
				ON DELETE CASCADE,
			PRIMARY KEY (`id`))")) {
			throw new Exception("Unable to create table addon_comments: " . $database->error());
		}
	}
}
?>
