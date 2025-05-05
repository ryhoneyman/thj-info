<?php

class csvView extends View {
   public function render($content) {
      header("Pragma: public");
      header("Cache-control: must-revalidate, post-check=0, pre-check=0");
      header("Content-type: application/octet-stream");
      header("Content-Disposition: attachment; filename=\"$method.csv\"");

      // ?????
      echo $func->csv_put_contents($content);
      return true;
   }
}
?>
