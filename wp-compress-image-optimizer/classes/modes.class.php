<?php
class wps_ic_modes extends wps_ic {

  public $wpc_filesystem;

  public function __construct()
  {
    #$this->wpc_filesystem = new WP_Filesystem_Direct('');
  }


  public function getFile($filePath) {
    // Fetch the image content
    $fileContent = $this->wpc_filesystem->get_contents($filePath);
    return $fileContent;
  }


  public function showPopup() {
    include WPS_IC_TEMPLATES . '/admin/selectModes/popup.php';
  }


  public function triggerPopup() {
    echo "<script type='text/javascript'>";
    echo "WPCSwal.fire({
            title: '',
            position: 'center',
            html: jQuery('#select-mode').html(),
            width: 900,
            showCloseButton: false,
            showCancelButton: false,
            showConfirmButton: false,
            allowOutsideClick: true,
            customClass: {
                container: 'no-padding-popup-bottom-bg switch-legacy-popup',
            },
            onOpen: function () {
                var modes_popup = $('.swal2-container .ajax-settings-popup');
                selectModesTrigger();
                hookCheckbox();
                saveMode(modes_popup);
            },
            onClose: function () {
                //openConfigurePopup(popup_modal);
            }
        });";
    echo '</script>';
  }


}