INSERT INTO site_settings (`key`, value) VALUES
  ('home_page_title_en', 'Local Florist & Flower Delivery in Tulsa, OK'),
  ('home_page_title_es', 'Florista Local y Entrega de Flores en Tulsa, OK'),
  ('hero_headline_en', 'Tulsa''s Custom Flower Boutique'),
  ('hero_subtext_en', 'Custom bouquets, fresh flowers, and floral arrangements handcrafted with love in Tulsa, OK. Order online or request a custom arrangement today.'),
  ('products_meta_desc_en', 'Browse handcrafted floral arrangements and fresh bouquets from Perla''s Flowers in Tulsa, OK. Eternal roses, fresh bouquets, custom orders welcome.'),
  ('products_page_title_en', 'Flowers & Arrangements'),
  ('about_meta_desc_en', 'Meet Perla — proud Mexican florist from Reynosa, handcrafting fresh and eternal flower arrangements in Tulsa, OK.')
ON DUPLICATE KEY UPDATE value = VALUES(value);
