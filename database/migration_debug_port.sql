-- Migration: them cot debug_port de dong bo tab qua Chrome DevTools Protocol
ALTER TABLE profiles ADD COLUMN debug_port INT DEFAULT NULL;

-- Moi profile duoc launch voi --remote-debugging-port rieng trong range 9200-9399
-- (uu tien 9200 + id; tach biet relay 9400+). Xem allocate_debug_port() trong config.php.
-- Giu thong tin trong DB de web app co the dieu khien tab qua CDP da co (XMLHTTP)