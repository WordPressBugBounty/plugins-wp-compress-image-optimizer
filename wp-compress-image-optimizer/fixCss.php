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

function fixCssRefuse()
{
  http_response_code(403);
  die();
}

function fixCssStylesheet($path)
{
  global $dirName;

  $root = realpath($dirName);
  $real = realpath($path);
  if ($root === false || $real === false) {
    return '';
  }

  $root = rtrim(str_replace('\\', '/', $root), '/');
  $real = str_replace('\\', '/', $real);

  if (strpos($real, $root . '/') === 0 && substr($real, -4) === '.css' && is_file($real)) {
    return $real;
  }

  return '';
}

function fixCssInsideRoot($path)
{
  global $dirName;

  $path = str_replace('\\', '/', $path);
  $root = rtrim(str_replace('\\', '/', $dirName), '/');

  $parts = [];
  foreach (explode('/', $path) as $part) {
    if ($part === '' || $part === '.') {
      continue;
    }

    if ($part === '..') {
      if (!$parts) {
        return false;
      }
      array_pop($parts);
      continue;
    }

    $parts[] = $part;
  }

  $normal = ($path !== '' && $path[0] === '/' ? '/' : '') . implode('/', $parts);

  return strpos($normal, $root . '/') === 0;
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
$rawCss = isset($_GET['css']) && is_string($_GET['css']) ? $_GET['css'] : '';
if ($rawCss === '') {
  die();
}

$forms = [$rawCss];
for ($level = 0; $level < 5; $level++) {
  $decoded = urldecode($forms[$level]);
  if ($decoded === $forms[$level]) {
    break;
  }
  $forms[] = $decoded;
}

foreach ($forms as $form) {
  if (strpos($form, '..') !== false || preg_match('/[\x00-\x1f\x7f\\\\]/', $form)) {
    fixCssRefuse();
  }
}

$cssUrl = urldecode($rawCss);

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
$zoneName = isset($_GET['zoneName']) && is_string($_GET['zoneName']) ? urldecode($_GET['zoneName']) : '';

// Info about file
$path = parse_url($cssUrl, PHP_URL_PATH);
$path = is_string($path) ? $path : '';

// Extract the extension
$extension = pathinfo($path, PATHINFO_EXTENSION);

// File extension is not css or not found
if ($extension !== 'css') {
  header('Location: ' . $cssUrl, 302);
  die();
}

// Get the filename
$cssFilename = basename($path);
$cssUrlPath = substr($cssUrl, 0, strcspn($cssUrl, '?#'));
$cssUrlPath = substr($cssUrlPath, 0, strrpos($cssUrlPath, '/') + 1);

$cssPath = trim(dirname($path), '/');

// Get clean ABSPATH
$dirName = str_replace('/wp-content/plugins/wp-compress-image-optimizer', '', dirname(__FILE__));
$filePath = fixCssStylesheet($dirName . '/' . $cssPath . '/' . $cssFilename);

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


          // Once again, check if the file exists in figured out path
          if (fixCssInsideRoot($dirName . '/' . $walker) && file_exists($dirName . '/' . $walker)) {
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