<?php
namespace EnableMediaReplace;
use EnableMediaReplace\ShortPixelLogger\ShortPixelLogger as Log;
use EnableMediaReplace\Notices\NoticeController as Notices;

/* Collection of functions helping the interface being cleaner. */
class UIHelper
{
  protected $preview_size = '';
  protected $preview_width = 0;
  protected $preview_height = 0;

  protected $preview_max_height = 500;
  protected $preview_max_width = 400;

  protected $full_width = 0;
  protected $full_height = 0;

  public function __construct()
  {

  }


  public function getFormUrl($attach_id)
  {
      $url = admin_url('upload.php');
      $url = add_query_arg(array(
          'page' => 'enable-media-replace/enable-media-replace.php',
          'noheader' => true,
          'action' => 'media_replace_upload',
          'attachment_id' => $attach_id,
      ));

      if (isset($_REQUEST['SHORTPIXEL_DEBUG']))
      {
        $spdebug = $_REQUEST['SHORTPIXEL_DEBUG'];
        if (is_numeric($spdebug))
          $spdebug = intval($spdebug);
        else {
          $spdebug = sanitize_text_field($spdebug);
        }

        $url = add_query_arg('SHORTPIXEL_DEBUG', $spdebug, $url);
      }

      return $url;

  }

  public function getSuccesRedirect($post_id)
  {
    $url = admin_url('post.php');
    $url = add_query_arg(array('action' => 'edit', 'post' => $post_id, 'emr_replaced' => '1'), $url);

    if (isset($_REQUEST['SHORTPIXEL_DEBUG']))
    {
      $spdebug = $_REQUEST['SHORTPIXEL_DEBUG'];
      if (is_numeric($spdebug))
        $spdebug = intval($spdebug);
      else {
        $spdebug = sanitize_text_field($spdebug);
      }

      $url = add_query_arg('SHORTPIXEL_DEBUG', $spdebug, $url);
    }

    $url = apply_filters('emr_returnurl', $url);
    Log::addDebug('Success URL- ' . $url);

    return $url;

  }

  public function getFailedRedirect($attach_id)
  {
    $url = admin_url('upload.php');
    $url = add_query_arg(array(
          'page' => 'enable-media-replace/enable-media-replace.php',
          'action' => 'media_replace',
          'attachment_id' => $attach_id,
          '_wpnonce' => wp_create_nonce('media_replace'),
        ), $url
    );

    $url = apply_filters('emr_returnurl_failed', $url);
    Log::addDebug('Failed URL- ' . $url);
    return $url;
  }



  public function setPreviewSizes()
  {
    list($this->preview_size, $this->preview_width, $this->preview_height) = $this->findImageSizeByMax($this->preview_max_width);
  }

  public function setSourceSizes($attach_id)
  {
    $data = $this->getImageSizes($attach_id, 'full');  // wp_get_attachment_image_src($attach_id, 'full');
  //  $file = get_attached_file($attach_id);

    if (is_array($data))
    {

      $this->full_width = $data[1];
      $this->full_height = $data[2];
    }

  }

  // Returns Preview Image HTML Output.
  public function getPreviewImage($attach_id,$file)
  {
      $data = false;

      if ($attach_id > 0)
      {
        $data = $this->getImageSizes($attach_id, $this->preview_size); //wp_get_attachment_image_src($attach_id, $this->preview_size);
        /*$file = get_attached_file($attach_id);

        // If the file is relative, prepend upload dir.
        if (! file_exists($file) && $file && 0 !== strpos( $file, '/' ) && ! preg_match( '|^.:\\\|', $file ) )
        {
          $file = get_post_meta( $attach_id, '_wp_attached_file', true );
          $uploads = wp_get_upload_dir();
          $file = $uploads['basedir'] . "/$file";
        }
        */
        Log::addDebug('Attached File '  . $file->getFullFilePath(), $data);
      }

      $mime_type = get_post_mime_type($attach_id);

      if (! is_array($data) || ! $file->exists() )
      {
        // if attachid higher than zero ( exists ) but not the image, fail, that's an error state.
        $icon = ($attach_id < 0) ? '' : 'dashicons-no';
        $is_document = false;

        $args = array(
            'width' => $this->preview_width,
            'height' => $this->preview_height,
            'is_image' => false,
            'is_document' => $is_document,
            'icon' => $icon,
            'mime_type' => null,
        );



        // failed, it might be this server doens't support PDF thumbnails. Fallback to File preview.
        if ($mime_type == 'application/pdf')
        {
            return $this->getPreviewFile($attach_id, $file);
        }

        return $this->getPlaceHolder($args);
      }


      $url = $data[0];
      $width = $data[1];
      $height = $data[2];

      // SVG's without any helpers return around 0 for width / height. Fix preview.

      // preview width, if source if found, should be set to source.
      $this->preview_width = $width;
      $this->preview_height = $height;


      if ($width > $this->preview_max_width)
        $width = $this->preview_max_width;
      if ($height > $this->preview_max_height)
        $height = $this->preview_max_height;

      $image = "<img src='$url' width='$width' height='$height' class='image' style='max-width:100%; max-height: 100%;' />";

      $args = array(
        'width' => $width,
        'height' => $height,
        'image' => $image,
        'mime_type' => $mime_type,
        'file_size' => $file->getFileSize(),
      );

      $output = $this->getPlaceHolder($args);
      return $output;
  }

  protected function getImageSizes($attach_id, $size = 'thumbnail')
  {
    $data = wp_get_attachment_image_src($attach_id, $size);
    $width = $data[1];
    //$mime_type = get_post_mime_type($attach_id);
    $file = get_attached_file($attach_id);
		$mime_type = wp_get_image_mime($file);

    if (strpos($mime_type, 'svg') !== false && $width <= 5)
    {
        $file = get_attached_file($attach_id);
        $data = $this->fixSVGSize($data, $file);
    }

    return $data;
  }

  protected function fixSVGSize($data, $file)
  {
    if (! function_exists('simplexml_load_file'))
      return $data;

    $xml = simplexml_load_file($file);
		//Log::addDebug('XML LOAD FILE', $xml);
    if ($xml)
    { // stolen from SVG Upload plugin
      $attr = $xml->attributes();
      $viewbox = explode(' ', $attr->viewBox);
      $data[1] = isset($attr->width) && preg_match('/\d+/', $attr->width, $value) ? (int) $value[0] : (count($viewbox) == 4 ? (int) $viewbox[2] : null);
      $data[2] = isset($attr->height) && preg_match('/\d+/', $attr->height, $value) ? (int) $value[0] : (count($viewbox) == 4 ? (int) $viewbox[3] : null);
    }

    return $data;
  }

  public function getPreviewError($attach_id)
  {
    $args = array(
      'width' => $this->preview_width,
      'height' => $this->preview_height,
      'icon' => 'dashicons-no',
      'is_image' => false,
    );
    $output = $this->getPlaceHolder($args);
    return $output;
  }

  public function getPreviewFile($attach_id, $file)
  {
    if ($attach_id > 0)
    {
      //$filepath = get_attached_file($attach_id);
      $filename = $file->getFileName();
    }
    else {
      $filename = false;
    }

    $mime_type = $file->getFileMime();

    $args = array(
      'width' => 300,
      'height' => 300,
      'is_image' => false,
      'is_document' => true,
      'layer' => $filename,
      'mime_type' => $mime_type,
      'file_size' => $file->getFileSize(),
    );
    $output = $this->getPlaceHolder($args);
    return $output;
  }

  public function findImageSizeByMax($maxwidth)
  {
      $image_sizes = $this->get_image_sizes();

      $match_width = 0;
      $match_height = 0;
      $match = '';

      foreach($image_sizes as $sizeName => $sizeItem)
      {

          $width = $sizeItem['width'];
          if ($width > $match_width && $width <= $maxwidth)
          {
            $match = $sizeName;
            $match_width = $width;
            $match_height = $sizeItem['height'];
          }
      }
      return array($match, $match_width, $match_height);
  }

  public function getPlaceHolder($args)
  {
    $defaults = array(
        'width' => 150,
        'height' => 150,
        'image' => '',
        'icon' => 'dashicons-media-document',
        'layer' =>  $this->full_width . ' x ' . $this->full_height,
        'is_image' => true,
        'is_document' => false,
        'mime_type' => false,
        'file_size' => false,
    );

    $args = wp_parse_args($args, $defaults);

    $w = $args['width'];
    $h = $args['height'];

    if ($w < 150)   // minimum
      $w = 150;
    if ($h < 150)
      $h = 150;

    $icon = $args['icon'];

    if ($args['is_image'])
    {
      $placeholder_class = 'is_image';
    }
    else {
      $placeholder_class = 'not_image';
    }

    if ($args['is_document'])
    {
      $placeholder_class .= ' is_document';
    }

    $filetype = '';
    if ($args['mime_type'])
    {
      $filetype = 'data-filetype="' . $args['mime_type'] . '"';
    }

    $filesize = ($args['file_size']) ? $args['file_size'] : '';


    $output = "<div class='image_placeholder $placeholder_class' $filetype style='width:" . $w . "px; height:". $h ."px'> ";
    $output .= $args['image'];
    $output .= "<div class='dashicons $icon'>&nbsp;</div>";
    $output .= "<span class='textlayer'>" . $args['layer'] . "</span>";
    $output .= "<div class='image_size'>" . $this->convertFileSize($filesize). "</div>";
    $output .= "</div>";



    return $output;
  }

  private function convertFileSize($filesize)
  {
     return size_format($filesize);
  }

    /**
  * Get size information for all currently-registered image sizes.
  * Directly stolen from - https://codex.wordpress.org/Function_Reference/get_intermediate_image_sizes
  * @global $_wp_additional_image_sizes
  * @uses   get_intermediate_image_sizes()
  * @return array $sizes Data for all currently-registered image sizes.
  */
  private function get_image_sizes() {
   global $_wp_additional_image_sizes;

   $sizes = array();

   foreach ( get_intermediate_image_sizes() as $_size ) {
     if ( in_array( $_size, array('thumbnail', 'medium', 'medium_large', 'large') ) ) {
       $sizes[ $_size ]['width']  = get_option( "{$_size}_size_w" );
       $sizes[ $_size ]['height'] = get_option( "{$_size}_size_h" );
     } elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
       $sizes[ $_size ] = array(
         'width'  => $_wp_additional_image_sizes[ $_size ]['width'],
         'height' => $_wp_additional_image_sizes[ $_size ]['height'],
       );
     }
   }

   return $sizes;
  }

  /** For Location Dir replacement. Get the Subdir that is in use now.  */
  public function getRelPathNow()
  {
      $uploadDir = wp_upload_dir();
      if (isset($uploadDir['subdir']))
        return ltrim($uploadDir['subdir'], '/');
      else
        return false;
  }

} // class
