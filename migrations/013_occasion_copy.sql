-- =============================================================================
-- Migration 013: admin-editable occasion copy
--
-- Adds per-occasion heading + blurb (EN/ES) so the occasion landing page's
-- title/H1/meta can be edited in the admin instead of living hardcoded in
-- Shop::occasionCopy(). Seeds the existing occasions with the copy that was
-- previously hardcoded so nothing regresses. (The virtual "hospital" group page
-- has no occasions row and keeps its copy in code.)
--
-- Run with the FULL database user (DDL access required). Runs once only.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE occasions
    ADD COLUMN heading_en VARCHAR(160) NULL AFTER name_es,
    ADD COLUMN heading_es VARCHAR(160) NULL AFTER heading_en,
    ADD COLUMN blurb_en   VARCHAR(300) NULL AFTER heading_es,
    ADD COLUMN blurb_es   VARCHAR(300) NULL AFTER blurb_en;

UPDATE occasions SET
    heading_en = 'Get Well Flowers',
    heading_es = 'Flores para Que te Mejores',
    blurb_en   = 'Brighten someone''s day and speed their recovery with a cheerful bouquet delivered with care.',
    blurb_es   = 'Alegra el día de alguien y ayuda a su recuperación con un ramo alegre entregado con cariño.'
    WHERE slug = 'get-well';

UPDATE occasions SET
    heading_en = 'New Baby Flowers',
    heading_es = 'Flores para Nuevo Bebé',
    blurb_en   = 'Welcome a new arrival with a fresh, beautiful arrangement that celebrates this joyful milestone.',
    blurb_es   = 'Da la bienvenida a un nuevo bebé con un arreglo fresco y hermoso que celebra este momento tan especial.'
    WHERE slug = 'new-baby';

UPDATE occasions SET
    heading_en = 'Sympathy Flowers',
    heading_es = 'Flores de Condolencias',
    blurb_en   = 'Express your deepest condolences with a thoughtful, elegant arrangement crafted with compassion.',
    blurb_es   = 'Expresa tus más sentidas condolencias con un arreglo elegante y pensativo elaborado con compasión.'
    WHERE slug = 'sympathy';

UPDATE occasions SET
    heading_en = 'Birthday Flowers',
    heading_es = 'Flores de Cumpleaños',
    blurb_en   = 'Make someone''s birthday unforgettable with a vibrant, hand-crafted arrangement tailored just for them.',
    blurb_es   = 'Haz el cumpleaños de alguien inolvidable con un arreglo vibrante y hecho a mano especialmente para esa persona.'
    WHERE slug = 'birthday';
