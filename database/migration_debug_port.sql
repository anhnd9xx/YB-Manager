-- Migration: them cot debug_port de dong bo tab qua Chrome DevTools Protocol
ALTER TABLE profiles ADD COLUMN debug_port INT DEFAULT NULL;

-- Moi profile duoc launch voi --remote-debugging-port rieng, cong 9200 + id
-- Giu thong tin trong DB de web app co the dieu khien tab qua CDP da co (XMLHTTP)