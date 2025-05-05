<?php

class HtmlView extends DefaultView {
  
   public $contentType = 'text/html; charset=utf8';

   public function render($data) {
      $content = var_export($data,true);
      return $content;
   }
}
?>
