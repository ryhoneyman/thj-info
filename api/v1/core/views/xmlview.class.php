<?php

class XmlView {
   public function render($content) {
      header('Content-Type: application/xml;');
      echo '<xml></xml>';
      return true;
   }
}
?>
