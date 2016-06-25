<?php
/**
 * @copyright Copyright (c) 2016 Lukas Reschke <lukas@statuscode.ch>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

class UpdateException extends \Exception {
	protected $data;

	public function __construct($data) {
		$this->data = $data;
	}

	public function getData() {
		return $this->data;
	}
}

class Updater {
	/** @var array */
	private $configValues = [];
	/** @var string */
	private $currentVersion = 'unknown';
	/** @var bool */
	private $updateAvailable = false;

	public function __construct() {
		$configFileName = __DIR__ . '/../config/config.php';
		if (!file_exists($configFileName)) {
			throw new \Exception('Could not find '.__DIR__.'/../config.php. Is this file in the "updater" subfolder of Nextcloud?');
		}

		/** @var array $CONFIG */
		require_once $configFileName;
		$this->configValues = $CONFIG;

		$versionFileName = __DIR__ . '/../version.php';
		if (!file_exists($versionFileName)) {
			// fallback to version in config.php
			$version = $this->getConfigOption('version');
		} else {
			/** @var string $OC_VersionString */
			require_once $versionFileName;
			$version = $OC_VersionString;
		}

		if($version === null) {
			return;
		}

		// normalize version to 3 digits
		$splittedVersion = explode('.', $version);
		if(sizeof($splittedVersion) >= 3) {
			$splittedVersion = array_slice($splittedVersion, 0, 3);
		}

		$this->currentVersion = implode('.', $splittedVersion);
	}

	/**
	 * Returns current version or "unknown" if this could not be determined.
	 *
	 * @return string
	 */
	public function getCurrentVersion() {
		return $this->currentVersion;
	}

	/**
	 * @return string
	 * @throws Exception
	 */
	public function checkForUpdate() {
		$response = $this->getUpdateServerResponse();

		$version = $response['version'];
		$versionString = $response['versionstring'];

		if ($version !== $this->currentVersion) {
			$this->updateAvailable = true;
			$updateText = 'Update to ' . $versionString . ' available.';
		} else {
			$updateText = 'No update available.';
		}

		return $updateText;
	}

	/**
	 * Returns bool whether update is available or not
	 *
	 * @return bool
	 */
	public function updateAvailable() {
		return $this->updateAvailable;
	}

	/**
	 * Returns the specified config options
	 *
	 * @param string $key
	 * @return mixed|null Null if the entry is not found
	 */
	private function getConfigOption($key) {
		return isset($this->configValues[$key]) ? $this->configValues[$key] : null;
	}

	/**
	 * Gets the data directory location on the local filesystem
	 *
	 * @return string
	 */
	private function getDataDirectoryLocation() {
		return $this->configValues['datadirectory'];
	}

	/**
	 * Returns the expected files and folders as array
	 *
	 * @return array
	 */
	private function getExpectedElementsList() {
		return $expectedElements = [
			// Generic
			'.',
			'..',
			// Folders
			'3rdparty',
			'apps',
			'config',
			'core',
			'data',
			'l10n',
			'lib',
			'ocs',
			'ocs-provider',
			'resources',
			'settings',
			'themes',
			'updater',
			// Files
			'index.html',
			'indie.json',
			'.user.ini',
			'console.php',
			'cron.php',
			'index.php',
			'public.php',
			'remote.php',
			'status.php',
			'version.php',
			'robots.txt',
			'.htaccess',
			'AUTHORS',
			'COPYING-AGPL',
			'occ',
			'db_structure.xml',
		];
	}

	/**
	 * Gets the recursive directory iterator over the Nextcloud folder
	 *
	 * @param string $folder
	 * @return RecursiveIteratorIterator
	 */
	private function getRecursiveDirectoryIterator($folder = null) {
		if ($folder === null) {
			$folder = __DIR__ . '/../';
		}
		return new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
	}

	/**
	 * Checks for files that are unexpected.
	 */
	public function checkForExpectedFilesAndFolders() {
		$expectedElements = $this->getExpectedElementsList();
		$unexpectedElements = [];
		foreach (new DirectoryIterator(__DIR__ . '/../') as $fileInfo) {
			if(array_search($fileInfo->getFilename(), $expectedElements) === false) {
				$unexpectedElements[] = $fileInfo->getFilename();
			}
		}

		if (count($unexpectedElements) !== 0) {
			throw new UpdateException($unexpectedElements);
		}
	}

	/**
	 * Checks for files that are not writable
	 */
	public function checkWritePermissions() {
		// TODO: Exclude data folder
		$notWritablePaths = array();
		foreach ($this->getRecursiveDirectoryIterator() as $path => $dir) {
			if(!is_writable($path)) {
				$notWritablePaths[] = $path;
			}
		}
		if(count($notWritablePaths) > 0) {
			throw new UpdateException($notWritablePaths);
		}
	}

	/**
	 * Sets the maintenance mode to the defined value
	 *
	 * @param bool $state
	 * @throws Exception when config.php can't be written
	 */
	public function setMaintenanceMode($state) {
		/** @var array $CONFIG */
		$configFileName = __DIR__ . '/../config/config.php';
		require $configFileName;
		$CONFIG['maintenance'] = $state;
		$content = "<?php\n";
		$content .= '$CONFIG = ';
		$content .= var_export($CONFIG, true);
		$content .= ";\n";
		$state = file_put_contents($configFileName, $content);
		if ($state === false) {
			throw new \Exception('Could not write to config.php');
		}
	}

	/**
	 * Creates a backup of all files and moves it into data/updater-$instanceid/backups/nextcloud-X-Y-Z/
	 *
	 * @throws Exception
	 */
	public function createBackup() {
		$excludedElements = [
			'data',
		];

		// Create new folder for the backup
		$backupFolderLocation = $this->getDataDirectoryLocation() . '/updater-'.$this->getConfigOption('instanceid').'/backups/nextcloud-'.$this->getConfigOption('version') . '/';
		if(file_exists($backupFolderLocation)) {
			$this->recursiveDelete($backupFolderLocation);
		}
		$state = mkdir($backupFolderLocation, 0750, true);
		if($state === false) {
			throw new \Exception('Could not create backup folder location');
		}

		// Copy the backup files
		$currentDir = __DIR__ . '/../';

		/**
		 * @var string $path
		 * @var SplFileInfo $fileInfo
		 */
		foreach ($this->getRecursiveDirectoryIterator($currentDir) as $path => $fileInfo) {
			$fileName = explode($currentDir, $path)[1];
			$folderStructure = explode('/', $fileName, -1);

			// Exclude the exclusions
			if(isset($folderStructure[0])) {
				if(array_search($folderStructure[0], $excludedElements) !== false) {
					continue;
				}
			} else {
				if(array_search($fileName, $excludedElements) !== false) {
					continue;
				}
			}

			// Create folder if it doesn't exist
			if(!file_exists($backupFolderLocation . '/' . dirname($fileName))) {
				$state = mkdir($backupFolderLocation . '/' . dirname($fileName), 0750, true);
				if($state === false) {
					throw new \Exception('Could not create folder: '.$backupFolderLocation.'/'.dirname($fileName));
				}
			}

			// If it is a file copy it
			if($fileInfo->isFile()) {
				$state = copy($fileInfo->getRealPath(), $backupFolderLocation . $fileName);
				if($state === false) {
					throw new \Exception(
						sprintf(
							'Could not copy "%s" to "%s"',
							$fileInfo->getRealPath(),
							$backupFolderLocation . $fileName
						)
					);
				}
			}
		}
	}

	/**
	 * @return array
	 * @throws Exception
	 */
	private function getUpdateServerResponse() {
		$updaterServer = $this->getConfigOption('updater.server.url');
		if($updaterServer === null) {
			// FIXME: used deployed URL
			$updaterServer = 'https://updates.nextcloud.org/updater_server/';
		}

		// Download update response
		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => 1,
			// TODO: Detect release channel, probably we want to write the channel to config.php for that
			CURLOPT_URL => $updaterServer . '?version='. str_replace('.', 'x', $this->getConfigOption('version')) .'xxxstablexx',
			CURLOPT_USERAGENT => 'Nextcloud Updater',
		]);
		$response = curl_exec($curl);
		if($response === false) {
			throw new \Exception('Could not do request to updater server: '.curl_error($curl));
		}
		curl_close($curl);

		$xml = simplexml_load_string($response);
		if($xml === false) {
			throw new \Exception('Could not parse updater server XML response');
		}
		$json = json_encode($xml);
		if($json === false) {
			throw new \Exception('Could not JSON encode updater server response');
		}
		$response = json_decode($json, true);
		if($response === null) {
			throw new \Exception('Could not JSON decode updater server response.');
		}
		return $response;
	}

	/**
	 * Downloads the nextcloud folder to $DATADIR/updater-$instanceid/downloads/$filename
	 *
	 * @throws Exception
	 */
	public function downloadUpdate() {
		$response = $this->getUpdateServerResponse();
		$storageLocation = $this->getDataDirectoryLocation() . '/updater-'.$this->getConfigOption('instanceid') . '/downloads/';
		if(file_exists($storageLocation)) {
			$this->recursiveDelete($storageLocation);
		}
		$state = mkdir($storageLocation, 0750, true);
		if($state === false) {
			throw new \Exception('Could not mkdir storage location');
		}

		$fp = fopen($storageLocation . basename($response['url']), 'w+');
		$ch = curl_init($response['url']);
		curl_setopt($ch, CURLOPT_FILE, $fp);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		if(curl_exec($ch) === false) {
			throw new \Exception('Curl error: ' . curl_error($ch));
		}
		curl_close($ch);
		fclose($fp);
	}

	/**
	 * Extracts the download
	 *
	 * @throws Exception
	 */
	public function extractDownload() {
		$storageLocation = $this->getDataDirectoryLocation() . '/updater-'.$this->getConfigOption('instanceid') . '/downloads/';
		$files = scandir($storageLocation);
		// ., .. and downloaded zip archive
		if(count($files) !== 3) {
			throw new \Exception('Not exact 3 files existent in folder');
		}

		$zip = new ZipArchive;
		$zipState = $zip->open($storageLocation . '/' . $files[2]);
		if ($zipState === true) {
			$zip->extractTo($storageLocation);
			$zip->close();
			$state = unlink($storageLocation . '/' . $files[2]);
			if($state === false) {
				throw new \Exception('Cant unlink '. $storageLocation . '/' . $files[2]);
			}
		} else {
			throw new \Exception('Cant handle ZIP file. Error code is: '.$zipState);
		}
	}

	/**
	 * Replaces the entry point files with files that only return a 503
	 *
	 * @throws Exception
	 */
	public function replaceEntryPoints() {
		$filesToReplace = [
			'index.php',
			'status.php',
			'remote.php',
			'public.php',
			'ocs/v1.php',
		];

		$content = "<?php\nhttp_response_code(503);\ndie('Update in process.');";
		foreach($filesToReplace as $file) {
			$parentDir = dirname(__DIR__ . '/../' . $file);
			if(!file_exists($parentDir)) {
				$r = mkdir($parentDir);
				if($r !== true) {
					throw new \Exception('Can\'t create parent directory for entry point: ' . $file);
				}
			}
			$state = file_put_contents(__DIR__  . '/../' . $file, $content);
			if($state === false) {
				throw new \Exception('Can\'t replace entry point: '.$file);
			}
		}
	}

	/**
	 * Recursively deletes the specified folder from the system
	 *
	 * @param string $folder
	 * @throws Exception
	 */
	private function recursiveDelete($folder) {
		if(!file_exists($folder)) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $fileInfo) {
			$action = $fileInfo->isDir() ? 'rmdir' : 'unlink';
			$action($fileInfo->getRealPath());
		}
		$state = rmdir($folder);
		if($state === false) {
			throw new \Exception('Could not rmdir ' . $folder);
		}
	}

	/**
	 * Delete old files from the system as much as possible
	 *
	 * @throws Exception
	 */
	public function deleteOldFiles() {
		$shippedAppsFile = __DIR__ . '/../core/shipped.json';
		if(!file_exists($shippedAppsFile)) {
			throw new \Exception('core/shipped.json is not available');
		}
		// Delete shipped apps
		$shippedApps = json_decode(file_get_contents($shippedAppsFile), true);
		foreach($shippedApps['shippedApps'] as $app) {
			$this->recursiveDelete(__DIR__ . '/../apps/' . $app);
		}

		$configSampleFile = __DIR__ . '/../config/config.sample.php';
		if(file_exists($configSampleFile)) {
			// Delete example config
			$state = unlink($configSampleFile);
			if ($state === false) {
				throw new \Exception('Could not unlink sample config');
			}
		}

		$themesReadme = __DIR__ . '/../themes/README';
		if(file_exists($themesReadme)) {
			// Delete themes
			$state = unlink($themesReadme);
			if ($state === false) {
				throw new \Exception('Could not delete themes README');
			}
		}
		$this->recursiveDelete(__DIR__ . '/../themes/example/');

		// Delete the rest
		$excludedElements = [
			'data',
			'index.php',
			'status.php',
			'remote.php',
			'public.php',
			'ocs/v1.php',
			'config',
			'themes',
			'apps',
			'updater',
		];
		/**
		 * @var string $path
		 * @var SplFileInfo $fileInfo
		 */
		foreach ($this->getRecursiveDirectoryIterator() as $path => $fileInfo) {
			$currentDir = __DIR__ . '/../';
			$fileName = explode($currentDir, $path)[1];
			$folderStructure = explode('/', $fileName, -1);
			// Exclude the exclusions
			if(isset($folderStructure[0])) {
				if(array_search($folderStructure[0], $excludedElements) !== false) {
					continue;
				}
			} else {
				if(array_search($fileName, $excludedElements) !== false) {
					continue;
				}
			}
			if($fileInfo->isFile()) {
				$state = unlink($path);
				if($state === false) {
					throw new \Exception('Could not unlink: '.$path);
				}
			} elseif($fileInfo->isDir()) {
				$state = rmdir($path);
				if($state === false) {
					throw new \Exception('Could not rmdir: '.$path);
				}
			}
		}
	}

	/**
	 * Moves the newly downloaded files into place
	 *
	 * @throws Exception
	 */
	public function moveNewVersionInPlace() {
		$excludedElements = [
			'updater',
			'index.php',
			'status.php',
			'remote.php',
			'public.php',
			'ocs/v1.php',
		];

		$storageLocation = $this->getDataDirectoryLocation() . '/updater-'.$this->getConfigOption('instanceid') . '/downloads/nextcloud/';

		/**
		 * @var SplFileInfo $fileInfo
		 */
		foreach ($this->getRecursiveDirectoryIterator($storageLocation) as $path => $fileInfo) {
			$fileName = explode($storageLocation, $path)[1];
			$folderStructure = explode('/', $fileName, -1);

			// Exclude the exclusions
			if (isset($folderStructure[0])) {
				if (array_search($folderStructure[0], $excludedElements) !== false) {
					continue;
				}
			} else {
				if (array_search($fileName, $excludedElements) !== false) {
					continue;
				}
			}

			if($fileInfo->isFile()) {
				if(!file_exists(__DIR__ . '/../' . dirname($fileName))) {
					$state = mkdir(__DIR__ . '/../' . dirname($fileName), 0750, true);
					if($state === false) {
						throw new \Exception('Could not mkdir ' . __DIR__  . '/../' . dirname($fileName));
					}
				}
				$state = rename($path, __DIR__  . '/../' . $fileName);
				if($state === false) {
					throw new \Exception(
						sprintf(
							'Could not rename %s to %s',
							$path,
							__DIR__ . '/../' . $fileName
						)
					);
				}
			}
			if($fileInfo->isDir()) {
				$state = rmdir($path);
				if($state === false) {
					throw new \Exception('Could not rmdir ' . $path);
				}
			}
		}

		// Rename entry files of Nextcloud and updater file
		/**
		 * @var SplFileInfo $fileInfo
		 */
		foreach ($this->getRecursiveDirectoryIterator($storageLocation) as $path => $fileInfo) {
			$fileName = explode($storageLocation, $path)[1];
			if($fileInfo->isFile()) {
				if(!file_exists(__DIR__ . '/../' . dirname($fileName))) {
					$state = mkdir(__DIR__ . '/../' . dirname($fileName), 0750, true);
					if($state === false) {
						throw new \Exception('Could not mkdir ' . __DIR__  . '/../' . dirname($fileName));
					}
				}
				$state = rename($path, __DIR__  . '/../' . $fileName);
				if($state === false) {
					throw new \Exception(
						sprintf(
							'Could not rename %s to %s',
							$path,
							__DIR__ . '/../' . $fileName
						)
					);
				}
			}
			if($fileInfo->isDir()) {
				$state = rmdir($path);
				if($state === false) {
					throw new \Exception('Could not rmdir ' . $path);
				}
			}
		}

		$state = rmdir($storageLocation);
		if($state === false) {
			throw new \Exception('Could not rmdir $storagelocation');
		}
	}
}

// Check if the config.php is at the expected place
try {
	$updater = new Updater();
} catch (\Exception $e) {
	die($e->getMessage());
}

// TODO: Note when a step started and when one ended, also to prevent multiple people at the same time accessing the updater
if(isset($_POST['step'])) {
	set_time_limit(0);
	try {

		switch ($_POST['step']) {
			case '1':
				$updater->checkForExpectedFilesAndFolders();
				break;
			case '2':
				$updater->checkWritePermissions();
				break;
			case '3':
				$updater->setMaintenanceMode(true);
				break;
			case '4':
				$updater->createBackup();
				break;
			case '5':
				$updater->downloadUpdate();
				break;
			case '6':
				$updater->extractDownload();
				break;
			case '7':
				// TODO: If it fails after step 7: Rollback
				$updater->replaceEntryPoints();
				break;
			case '8':
				$updater->deleteOldFiles();
				break;
			case '9':
				$updater->moveNewVersionInPlace();
				$updater->setMaintenanceMode(false);
				break;
		}
		echo(json_encode(['proceed' => true]));
	} catch (UpdateException $e) {
		echo(json_encode(['proceed' => false, 'response' => $e->getData()]));
	} catch (\Exception $e) {
		echo(json_encode(['proceed' => false, 'response' => $e->getMessage()]));
	}

	die();
}
?>

<html>
<head>
	<style>
		html, body, div, span, object, iframe, h1, h2, h3, h4, h5, h6, p, blockquote, pre, a, abbr, acronym, address, code, del, dfn, em, img, q, dl, dt, dd, ol, ul, li, fieldset, form, label, legend, table, caption, tbody, tfoot, thead, tr, th, td, article, aside, dialog, figure, footer, header, hgroup, nav, section {
			margin: 0;
			padding: 0;
			border: 0;
			outline: 0;
			font-weight: inherit;
			font-size: 100%;
			font-family: inherit;
			vertical-align: baseline;
			cursor: default;
		}
		body {
			font-family: 'Open Sans', Frutiger, Calibri, 'Myriad Pro', Myriad, sans-serif;
			background-color: #ffffff;
			font-weight: 400;
			font-size: .8em;
			line-height: 1.6em;
			color: #000;
			height: auto;
		}
		a {
			border: 0;
			color: #000;
			text-decoration: none;
			cursor: pointer;
		}
		ul {
			list-style: none;
		}
		#header {
			position: fixed;
			top: 0;
			left: 0;
			right: 0;
			height: 45px;
			line-height: 2.5em;
			background-color: #0082c9;
			box-sizing: border-box;
		}
		.header-appname {
			color: #fff;
			font-size: 20px;
			font-weight: 300;
			line-height: 45px;
			padding: 0;
			margin: 0;
			display: inline-block;
			position: absolute;
			margin-left: 5px;
		}
		#header svg {
			margin: 5px;
		}

		#content-wrapper {
			position: absolute;
			height: 100%;
			width: 100%;
			overflow-x: hidden;
			padding-top: 45px;
			box-sizing: border-box;
		}

		#content {
			position: relative;
			height: 100%;
			margin: 0 auto;
		}
		#app-navigation {
			width: 250px;
			height: 100%;
			float: left;
			box-sizing: border-box;
			background-color: #fff;
			padding-bottom: 44px;
			-webkit-user-select: none;
			-moz-user-select: none;
			-ms-user-select: none;
			user-select: none;
			border-right: 1px solid #eee;
		}
		#app-navigation > ul {
			position: relative;
			height: 100%;
			width: inherit;
			overflow: auto;
			box-sizing: border-box;
		}
		#app-navigation li {
			position: relative;
			width: 100%;
			box-sizing: border-box;
		}
		#app-navigation li > a {
			display: block;
			width: 100%;
			line-height: 44px;
			min-height: 44px;
			padding: 0 12px;
			overflow: hidden;
			box-sizing: border-box;
			white-space: nowrap;
			text-overflow: ellipsis;
			color: #000;
			opacity: .57;
		}
		#app-navigation li:hover > a, #app-navigation li:focus > a {
			opacity: 1;
		}


		#app-content {
			position: relative;
			height: 100%;
			overflow-y: auto;
		}
		#progress {
			width: 600px;
		}
		.section {
			padding: 25px 30px;
		}
		.hidden {
			display: none;
		}

		li.step{
			-ms-filter: "progid:DXImageTransform.Microsoft.Alpha(Opacity=57)";
			opacity: .57;
		}

		li.step h2 {
			padding: 5px 2px 5px 30px;
			margin-top: 12px;
			margin-bottom: 0;
			-ms-filter: "progid:DXImageTransform.Microsoft.Alpha(Opacity=57)";
			opacity: .57;
			background-position:8px 50%;
			background-repeat: no-repeat;
		}

		li.current-step, li.passed-step, li.failed-step{
			-ms-filter: "progid:DXImageTransform.Microsoft.Alpha(Opacity=100)";
			opacity: 1;
		}

		li.current-step h2 {
			background-image: url(data:image/gif;base64,R0lGODlhEAAQAOMAAP///zMzM9HR0ZycnMTExK6url5eXnd3d9/f3+np6cnJyUpKSjY2Nv///////////yH/C05FVFNDQVBFMi4wAwEAAAAh+QQJCgAPACwAAAAAEAAQAAAETvDJ+UqhWA7JmCSZtIDdo4ChsTwlkWDG9Szb9yQEehgGkuUKGCpE/AEHyJqRECxKfBjEkJJ7fZhRycmHkwhA4CmG4EORQyfb4xuyPsSSCAAh+QQJCgAPACwAAAAAEAAQAAAEUvDJ+QqhWBa5lmSZZChPV4LhYZQLwmzUQD7GMIEJcT3EMCQZ3WwyEISORx1BoVAmhcgJIoPYYXRAic5ImT6a05xEcClbg9MdwYtpasfnSZYXigAAIfkECQoADwAsAAAAABAAEAAABFDwyfkIoVgqaYxcmTQgT1eCYTGURrJcTyIR5DPAD1gwjCRYMgwPNaGFaqGMhaBQLJPLTXKCpOIowCJBgKk5SQnYr1K5YowwY8Y585klQXImAgAh+QQJCgAPACwAAAAAEAAQAAAEUPDJ+YSgWCI5hjSZRCRP9xxgqBDlkBjsQz7ERtsPSCyLJBCjDC81qYVmoQxjuVgBk0tGLznBVWMYIBJ4odhWm0TsR6NhM8aYMbMS+c6TbSgCACH5BAkKAA8ALAAAAAAQABAAAARQ8Mn5EKJY3leKHJlEJJw3gKFClMmwkQ+xyRNIGIYkEGOGHxhaBhbKLI4GFa94XOSKtQxilWEwPCKCALNZMEAJ6i4Wo4ZoVCFGJdKZKcT3JAIAIfkECQoADwAsAAAAABAAEAAABFDwyflSolgiSYgsGXd1DwGGitclxVZxLuGWDzIMkrBmN07JoUsoZCgeUiSicUjxURCezGIRLREEmAHWsMAlojoag8EERhlOSoojMZAzQlomAgAh+QQJCgAPACwAAAAAEAAQAAAEUPDJ+VKiWCJJCM/c1T2KB5ZPlxBXxW0pnFbjI6hZp2CETLWgzGBYKNWExCBlkEGYMAbDsyPAFKoHQ4EmuT0Yj8VC2ftKFswMyvw4jDNAcCYCACH5BAkKAA8ALAAAAAAQABAAAARQ8Mn5UqJYIkkIz9zVPYoHlk+XEFfFbSmcVuMjqFmnYIRMtaCcrlQTEnbBiYmCWFIGA1lHwNtAdyuJgfFYPAyGJGPQ1RZAC275cQhnzhJvJgIAIfkECQoADwAsAAAAABAAEAAABFHwyflSolgiSQjP3NU9igeWT5cQV8VtKZxW4yOoWadghEy1oJyuVBMSdsGJTzJggHASBsOAEVxKm4LzcVg8qINBciGmPgZIjMH7lRTEuYkZEwEAIfkECQoADwAsAAAAABAAEAAABE/wyflSolgiSQjP3NU9igeWT5cQV8VtKZxW4yOoWadghEy1oJyOQWQEO4RdcOKTDBYgnGSxOGAQl9KGAH0cDI9BygQyFMKvMhhtI1PI4kwEACH5BAkKAA8ALAAAAAAQABAAAARQ8Mn5UqJYIkkIz9zVPYoHlk+XEFclMQO3fatpMIyQdQoGgy3QjofDCTuEnnAyoxQMINXEYDhgEJfShgB9FGKekXDQMxGalEEsJRGYrpM3JQIAIfkEAQoADwAsAAAAABAAEAAABFHwyflSolgOSQjPEuN1j+KBC/N0CXFV0rI9zDF57XksC5J1CsyiAHqBfkCD0nDsEILHiQ+jmGFYk8GASEFcTD7ETDBanUAE3ykNMn0e5OINFAEAOw==);
		}

		li.current-step h2, li.passed-step h2 {
			-ms-filter: "progid:DXImageTransform.Microsoft.Alpha(Opacity=100)";
			opacity: 1;
		}

		li.passed-step h2 {
			cursor : pointer;
			background-image: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAMAAAAoLQ9TAAAAWlBMVEUAAAAAqgAAvwAA1QAA3wAAxgAA0QAA1QAAzgAA0QAA1gAA1gAA1wAA1gAA0gAA1QAA1AAA1AAA1AAA1QAA0wAA1AAA1AAA1QAA0wAA1AAA1AAA1QAA1AAA1ACEAd/9AAAAHXRSTlMAAwQGCAkLDBUWGR8gLC2osrO3uru9v9LT1Nfq+K5OpOQAAABPSURBVBiVpYq3EYAwEMBEfnJONr//mhSYI5SgTifBPyLv5UPtP11tAZDI4b3aEiCeTAYErdoKAFl0TQk71wGZ1eTN2d2zXd09tw4gY8l3dg+HBDK71PO7AAAAAElFTkSuQmCC);
		}

		li.failed-step h2 {
			background-color: #ffb0b0;
			background-image: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAMAAAAoLQ9TAAAAPFBMVEUAAACqAADMAADVAADVAADVAADVAADWAADWAADUAADUAADUAADVAADUAADTAADVAADUAADVAADUAADUAACCP69rAAAAE3RSTlMAAwUGDCorMjiHpaeosdPk6ervRw2uZQAAAERJREFUeAFjIA4w8QoDgRA7jM/ILQwGgmxQPheQw8HAJywswAoW4BSGCQjzM4MEeBACwizECiAAC4ah6NZiOgzT6YQBABtYB8QyiY2BAAAAAElFTkSuQmCC);
			-ms-filter: "progid:DXImageTransform.Microsoft.Alpha(Opacity=100)";
			opacity: 1;
		}

		li.step .output {
			position: relative;
			padding: 5px 5px 5px 32px;
		}

		h2 {
			font-size: 20px;
			font-weight: 300;
			margin-bottom: 12px;
			color: #555;
		}


	</style>
</head>
<body>
<div id="header">
	<svg xmlns="http://www.w3.org/2000/svg" version="1.1" xml:space="preserve" height="34" width="62" enable-background="new 0 0 196.6 72" y="0px" x="0px" viewBox="0 0 62.000002 34"><path style="color-rendering:auto;text-decoration-color:#000000;color:#000000;isolation:auto;mix-blend-mode:normal;shape-rendering:auto;solid-color:#000000;block-progression:tb;text-decoration-line:none;image-rendering:auto;white-space:normal;text-indent:0;enable-background:accumulate;text-transform:none;text-decoration-style:solid" fill="#fff" d="m31.6 4.0001c-5.95 0.0006-10.947 4.0745-12.473 9.5549-1.333-2.931-4.266-5.0088-7.674-5.0092-4.6384 0.0005-8.4524 3.8142-8.453 8.4532-0.0008321 4.6397 3.8137 8.4544 8.4534 8.455 3.4081-0.000409 6.3392-2.0792 7.6716-5.011 1.5261 5.4817 6.5242 9.5569 12.475 9.5569 5.918 0.000457 10.89-4.0302 12.448-9.4649 1.3541 2.8776 4.242 4.9184 7.6106 4.9188 4.6406 0.000828 8.4558-3.8144 8.4551-8.455-0.000457-4.6397-3.8154-8.454-8.4551-8.4533-3.3687 0.0008566-6.2587 2.0412-7.6123 4.9188-1.559-5.4338-6.528-9.4644-12.446-9.464zm0 4.9623c4.4687-0.000297 8.0384 3.5683 8.0389 8.0371 0.000228 4.4693-3.5696 8.0391-8.0389 8.0388-4.4687-0.000438-8.0375-3.5701-8.0372-8.0388 0.000457-4.4682 3.5689-8.0366 8.0372-8.0371zm-20.147 4.5456c1.9576 0.000226 3.4908 1.5334 3.4911 3.491 0.000343 1.958-1.533 3.4925-3.4911 3.4927-1.958-0.000228-3.4913-1.5347-3.4911-3.4927 0.0002284-1.9575 1.5334-3.4907 3.4911-3.491zm40.205 0c1.9579-0.000343 3.4925 1.533 3.4927 3.491 0.000457 1.9584-1.5343 3.493-3.4927 3.4927-1.958-0.000228-3.4914-1.5347-3.4911-3.4927 0.000221-1.9575 1.5335-3.4907 3.4911-3.491z"/></svg>
	<h1 class="header-appname">Nextcloud Updater</h1>
</div>

<div id="content-wrapper">
	<div id="content">

		<div id="app-navigation">
			<ul>
				<li><a href="#progress">Update</a></li>
			</ul>
		</div>
		<div id="app-content">
			<div id="error" class="section hidden"></div>
			<div id="output" class="section hidden"></div>

			<ul id="progress" class="section">
				<li id="step-init" class="step icon-loading passed-step">
					<h2>Initializing</h2>
					<div class="output">Current version is <?php echo($updater->getCurrentVersion()); ?>.<br>
						<?php echo($updater->checkForUpdate()); ?><br>
						<button id="recheck" class="button" onClick="window.location.reload()">Recheck</button></div>
				</li>
				<li id="step-check-files" class="step">
					<h2>Check for expected files</h2>
					<div class="output hidden"></div>
				</li>
				<li id="step-check-permissions" class="step">
					<h2>Check for write permissions</h2>
					<div class="output hidden"></div>
				</li>
				<li id="step-enable-maintenance" class="step">
					<h2>Enable maintenance mode</h2>
					<div class="output hidden"></div>
				</li>
				<li id="step-backup" class="step">
					<h2>Create backup</h2>
					<div class="output hidden"></div>
				</li>
				<li id="step-download" class="step">
					<h2>Downloading</h2>
					<div class="output hidden"></div>
				</li>
				<li id="step-extract" class="step">
					<h2>Extracting</h2>
					<div class="output hidden"></div>
				</li>
				<li id="step-entrypoints" class="step">
					<h2>Replace entry points</h2>
					<div class="output hidden"></div>
				</li>
				<li id="step-delete" class="step">
					<h2>Delete old files</h2>
					<div class="output hidden"></div>
				</li>
				<li id="step-move" class="step">
					<h2>Move new files in place</h2>
					<div class="output hidden"></div>
				</li>
				<li id="step-done" class="step">
					<h2>Done</h2>
					<div class="output hidden"></div>
				</li>
			</ul>

		</div>
	</div>
</div>

<?php
	// TODO: Proper auth also in the steps above…
if(false):
	?>
	<p>Please provide your defined password in config.php to proceed:</p>
	<form method="POST">
		<input type="password" name="password" />
		<input type="submit" />
	</form>
<?php endif; ?>

<?php if(true): ?>
	<pre id="progress">
Starting update process. Please be patient...
	</pre>
<?php endif; ?>
</body>
<?php if(true): ?>

	<script>
		function addStepText(text) {
			var previousValue = document.getElementById('progress').innerHTML;
			document.getElementById('progress').innerHTML = previousValue + "\r" + text;
		}

		function currentStep(id) {
			var el = document.getElementById(id);
			el.classList.remove('failed-step');
			el.classList.remove('passed-step');
			el.classList.add('current-step');
		}

		function errorStep(id) {
			var el = document.getElementById(id);
			el.classList.remove('passed-step');
			el.classList.remove('current-step');
			el.classList.add('failed-step');
		}

		function successStep(id) {
			var el = document.getElementById(id);
			el.classList.remove('failed-step');
			el.classList.remove('current-step');
			el.classList.add('passed-step');
		}

		function performStep(number, callback) {
			var httpRequest = new XMLHttpRequest();
			httpRequest.open("POST", window.location.href);
			httpRequest.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
			httpRequest.onreadystatechange = function () {
				if (httpRequest.readyState != 4) { // 4 - request done
					return;
				}

				if (httpRequest.status != 200) {
					// failure
				}

				callback(JSON.parse(httpRequest.responseText));
			};
			httpRequest.send("step="+number);
		}


		var performStepCallbacks = {
			1: function(response) {
				if(response.proceed === true) {
					successStep('step-check-files');
					currentStep('step-check-permissions');
					performStep(2, performStepCallbacks[2])
				} else {
					errorStep('step-check-files');
					// TODO
					addStepText('The following extra files have been found:');
					response['response'].forEach(function(file) {
						addStepText("\t"+file);
					});
				}
			},
			2: function(response) {
				if(response.proceed === true) {
					successStep('step-check-permissions');
					currentStep('step-enable-maintenance');
					performStep(3, performStepCallbacks[3]);
				} else {
					errorStep('step-check-permissions');
					// TODO
					addStepText('The following places can not be written to:');
					response['response'].forEach(function(file) {
						addStepText("\t"+file);
					});
				}
			},
			3: function(response) {
				if(response.proceed === true) {
					successStep('step-enable-maintenance');
					currentStep('step-backup');
					performStep(4, performStepCallbacks[4]);
				} else {
					errorStep('step-enable-maintenance');
				}
			},
			4: function(response) {
				successStep('step-backup');
				currentStep('step-download');
				performStep(5, performStepCallbacks[5]);
			},
			5: function(response) {
				successStep('step-download');
				currentStep('step-extract');
				performStep(6, performStepCallbacks[6]);
			},
			6: function(response) {
				successStep('step-extract');
				currentStep('step-entrypoints');
				performStep(7, performStepCallbacks[7]);
			},
			7: function(response) {
				successStep('step-entrypoints');
				currentStep('step-delete');
				performStep(8, performStepCallbacks[8]);
			},
			8: function(response) {
				successStep('step-delete');
				currentStep('step-move');
				performStep(9, performStepCallbacks[9]);
			},
			9: function(response) {
				successStep('step-move');
				successStep('step-done');
			}
		};

		<?php
		if ($updater->updateAvailable()) {
			?>
			currentStep('step-check-files');
			performStep(1, performStepCallbacks[1]);
			<?php
		}
		?>
	</script>
<?php endif; ?>

</html>

