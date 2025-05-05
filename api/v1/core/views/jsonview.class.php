<?php

class JsonView extends DefaultView {

   public $contentType = 'application/json; charset=utf8';

   public function render($data = null) {
      $content = json_encode($data,JSON_INVALID_UTF8_IGNORE);
      $this->contentBody = $content;
      return true;
   }
}
?>
