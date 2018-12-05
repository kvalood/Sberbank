<?php

require_once('api/Simpla.php');
$simpla = new Simpla();

/**
 * Кастомный НДС
 * для каждого товара
 */
$query = $simpla->db->placehold("ALTER TABLE __products ADD taxType TINYINT NULL AFTER visible;");
$simpla->db->query($query);


?>