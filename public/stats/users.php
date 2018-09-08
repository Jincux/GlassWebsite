<?php
	require dirname(__DIR__) . '/../private/autoload.php';
	use Glass\GroupManager;
	use Glass\UserManager;
	use Glass\UserLog;
	use Glass\StatUsageManager;

	$_PAGETITLE = "Current Users | Blockland Glass";

	include(realpath(dirname(__DIR__) . "/../private/header.php"));

	$users = UserLog::getRecentlyActive();
?>
<style>
.list td {
  padding: 10px;
}

.list tr:nth-child(2n+1) td {
  background-color: #ddd;
}

.list tr:first-child td {
  background-color: #777;
  color: #fff;
  font-weight: bold;
}

.list tr td:first-child {
  border-radius: 10px 0 0 10px;
}

.list tr td:last-child {
  border-radius: 0 10px 10px 0;
}

.list {
  margin: 0 auto;
}

.maincontainer p {
  text-align: center;
}

form {
  text-align: center;
}

</style>
<div class="maincontainer">
  <?php
    include(realpath(dirname(__DIR__) . "/../private/navigationbar.php"));
  ?>
	<table class="list">
    <tbody>
      <tr>
        <td>Username</td>
        <td>BL_ID</td>
        <td>Version</td>
      </tr>
			<?php foreach($users as $u) {
				$username = utf8_encode(UserLog::getCurrentUsername($u->blid));
				echo "<tr><td><strong>" . $username . "</strong></td><td>" . $u->blid . "</td><td>" . StatUsageManager::getVersionUsed($u->blid, 11) . "</td></tr>";
			} ?>
		</tbody>
	</table>
</div>
