START TRANSACTION;
---
--- Добавить колонку для записи текущего языка сайта.
---
ALTER TABLE `ps_emailsubscription` 
	ADD `id_lang` INT(10) UNSIGNED 
	NULL DEFAULT NULL;

COMMIT;
