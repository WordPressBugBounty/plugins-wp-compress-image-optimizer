<?php
global $zoneName, $cssUrlPath, $cssUrl, $zoneName, $cssPath, $dirName, $siteUrl;

$debug = false;

function pathWalker($path, $find)
{
  $paths = explode('/', $path);
  $foldersUp = substr_count($find, '../');

  $array = array_splice($paths, 0, -$foldersUp);
  $array = implode('/', $array);

  return $array;
}

// Code Here
if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
  $protocol = 'https://';
} else {
  $protocol = 'http://';
}

// Get the current site URL
$siteUrl = $protocol . $_SERVER['HTTP_HOST'];

// Decode the URL, because it's encoded in GET
$cssUrl = urldecode($_GET['css']);

// Parse the URLs to get the host components
$siteHostValidation = parse_url($siteUrl, PHP_URL_HOST);
$cssHostValidation = parse_url($cssUrl, PHP_URL_HOST);

// Check if the hosts match
if ($siteHostValidation === $cssHostValidation) {
	//All good
} else {
	die();
}


// Zone Name
$zoneName = urldecode($_GET['zoneName']);

// Info about file
$path = parse_url($cssUrl, PHP_URL_PATH);

// Get the filename and extension using pathinfo
$fileInfo = pathinfo($path);

// Extract filename and extension
$extension = $fileInfo['extension'];

// File extension is not css or not found
if (!$extension || $extension !== 'css') {
  header('Location: ' . $cssUrl, 302);
  die();
}

// Get the filename
$cssFilename = basename($cssUrl);
$cssUrlPath = str_replace($cssFilename, '', $cssUrl);

$cssFilename = explode('?', $cssFilename);
$cssFilename = $cssFilename[0];

// Remove the site URL from the Path to retrieve just the path
$cssPath = str_replace([$siteUrl . '/', 'http://' . $_SERVER['HTTP_HOST'] . '/'], '', $cssUrlPath);
$cssPath = rtrim($cssPath, '/');

// Get clean ABSPATH
$dirName = str_replace('/wp-content/plugins/wp-compress-image-optimizer', '', dirname(__FILE__));
$filePath = $dirName . '/' . $cssPath . '/' . $cssFilename;

function replaceCSS($matches)
{
  global $zoneName, $cssUrlPath, $cssUrl, $zoneName, $cssPath, $dirName, $siteUrl;

  if (!empty($matches)) {
    $foundUrls = trim($matches[1]);

    if (strpos($foundUrls, 'data:') !== false) {
      return 'url("' . $foundUrls . '")';
    } else {

      $foundUrls = str_replace('("', '', $foundUrls);
      $foundUrls = str_replace("('", '', $foundUrls);
      $foundUrls = str_replace('")', '', $foundUrls);
      $foundUrls = str_replace("')", '', $foundUrls);

      // Remove the wrapping brackets
      $foundUrls = rtrim($foundUrls, ')');
      $foundUrls = ltrim($foundUrls, '(');
      $foundUrls = trim($foundUrls);

      // If the found url has // or http/s, just set on CDN?
      if (strpos($foundUrls, '//') === 0 || strpos($foundUrls, 'http') === 0) {
        // Real URL, leave alone?
        return 'url("' . $foundUrls . '")';
      } else {

        // Remove the wrapping brackets
        $foundUrls = rtrim($foundUrls, ')');
        $foundUrls = ltrim($foundUrls, '(');

        // If the found url has at least one ../ then do something with it
        if (strpos($foundUrls, '../') !== false) {
          // Get just the clean path, without ../
          $removeRelative = str_replace('../', '', $foundUrls);

          // Explode ? so that we remove query var "../whatever.css?ver=12.94#4e0
          $removedQueryVar = explode('?', $removeRelative);
          $removedQueryVar = $removedQueryVar[0];

          // Run walker, this one figures out the actual path depending on number of folders up
          $walker = pathWalker($cssPath, $foundUrls);

          // Add the filename
          $walker .= '/' . $removedQueryVar;

          if (!empty($_GET['debugWalker2'])) {
            return print_r([$dirName, $walker, $cssPath, $foundUrls], true);
          }

          // Once again, check if the file exists in figured out path
          if (file_exists($dirName . '/' . $walker)) {
            return 'url("' . $siteUrl . '/' . $walker . '")';
          }
        } elseif (strpos($foundUrls, './') !== false) {

          // Same folder
          $foundUrls = ltrim($foundUrls, '(');
          $foundUrls = rtrim($foundUrls, ')');

          // Get just the clean path, without ../
          $removeRelative = str_replace('./', '', $foundUrls);

          // Once again, check if the file exists in figured out path
          return 'url("' . $cssUrlPath . $removeRelative . '")';
        } elseif (strpos($foundUrls, '/wp-content') !== false && strpos($foundUrls, '/wp-content') == 0) {

          $foundUrls = str_replace('("', '', $foundUrls);
          $foundUrls = str_replace("('", '', $foundUrls);
          $foundUrls = str_replace('")', '', $foundUrls);
          $foundUrls = str_replace("')", '', $foundUrls);
          return 'url("' . $siteUrl . $foundUrls . '")';
        } else {
          // Let's guess it's in root where the file is
          $cleanUrl = $foundUrls;
          return 'url("' . $cssUrlPath . $foundUrls . '")';
        }
      }
    }
  }
}

// Check if the CSS file exists, as that way we know we have the correct PATH
if (file_exists($filePath)) {
  $fileContents = file_get_contents($filePath);

  if (!empty($fileContents)) {
    // Igniore the error in regexp below!! IDE IS STUPID
    $re = '/url(\(((?:[^()]+|(?1))+)\))/m';
    $fileContents = preg_replace_callback($re, 'replaceCSS', $fileContents);

    $ts = gmdate("D, d M Y H:i:s") . " GMT";
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + ((60 * 60 * 24 * 365)))); // 1 year
    header("Last-Modified: $ts");
    header('Cache-Control:public max-age=84600, s-maxage=84600');
    header('Content-Type:text/css');
    $fileContents = trim($fileContents);
    // TODO: Creates broken CSS on hypereffects.com
    echo $fileContents;
    die();
  }
}

header('Location: ' . $cssUrl, 302);
die();