<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: fixCss.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */

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


if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
  $protocol = 'https://';
} else {
  $protocol = 'http://';
}


$siteUrl = $protocol . $_SERVER['HTTP_HOST'];


$cssUrl = urldecode($_GET['css']);


$siteHostValidation = parse_url($siteUrl, PHP_URL_HOST);
$cssHostValidation = parse_url($cssUrl, PHP_URL_HOST);


if ($siteHostValidation === $cssHostValidation) {
	
} else {
	die();
}



$zoneName = urldecode($_GET['zoneName']);


$path = parse_url($cssUrl, PHP_URL_PATH);


$fileInfo = pathinfo($path);


$extension = $fileInfo['extension'];


if (!$extension || $extension !== 'css') {
  header('Location: ' . $cssUrl, 302);
  die();
}


$cssFilename = basename($cssUrl);
$cssUrlPath = str_replace($cssFilename, '', $cssUrl);

$cssFilename = explode('?', $cssFilename);
$cssFilename = $cssFilename[0];


$cssPath = str_replace([$siteUrl . '/', 'http://' . $_SERVER['HTTP_HOST'] . '/'], '', $cssUrlPath);
$cssPath = rtrim($cssPath, '/');


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

      
      $foundUrls = rtrim($foundUrls, ')');
      $foundUrls = ltrim($foundUrls, '(');
      $foundUrls = trim($foundUrls);

      
      if (strpos($foundUrls, '//') === 0 || strpos($foundUrls, 'http') === 0) {
        
        return 'url("' . $foundUrls . '")';
      } else {

        
        $foundUrls = rtrim($foundUrls, ')');
        $foundUrls = ltrim($foundUrls, '(');


        if (strpos($foundUrls, '../') !== false) {
          
          $removeRelative = str_replace('../', '', $foundUrls);

          
          $removedQueryVar = explode('?', $removeRelative);
          $removedQueryVar = $removedQueryVar[0];

          
          $walker = pathWalker($cssPath, $foundUrls);

          
          $walker .= '/' . $removedQueryVar;


          
          if (file_exists($dirName . '/' . $walker)) {
            return 'url("' . $siteUrl . '/' . $walker . '")';
          }
        } elseif (strpos($foundUrls, './') !== false) {

          
          $foundUrls = ltrim($foundUrls, '(');
          $foundUrls = rtrim($foundUrls, ')');

          
          $removeRelative = str_replace('./', '', $foundUrls);

          
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


if (file_exists($filePath)) {
  $fileContents = file_get_contents($filePath);

  if (!empty($fileContents)) {
    
    $re = '/url(\(((?:[^()]+|(?1))+)\))/m';
    $fileContents = preg_replace_callback($re, 'replaceCSS', $fileContents);

    $ts = gmdate("D, d M Y H:i:s") . " GMT";
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + ((60 * 60 * 24 * 365)))); 
    header("Last-Modified: $ts");
    header('Cache-Control:public max-age=84600, s-maxage=84600');
    header('Content-Type:text/css');
    $fileContents = trim($fileContents);
    
    echo $fileContents;
    die();
  }
}

header('Location: ' . $cssUrl, 302);
die();