<?php

namespace Drupal\incoming_views_pdf_tweaks\Encoder;

use Drupal\xls_serialization\Encoder\Xls;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * XLS encoder that draws borders for all used cells.
 */
class BorderedXls extends Xls {

  /**
   * {@inheritdoc}
   */
  protected function setRowsAutoHeight(Worksheet $sheet) {
    parent::setRowsAutoHeight($sheet);

    $sheet->getStyle('A1:' . $sheet->getHighestDataColumn() . $sheet->getHighestDataRow())
      ->getBorders()
      ->getAllBorders()
      ->setBorderStyle(Border::BORDER_THIN);
  }

}

