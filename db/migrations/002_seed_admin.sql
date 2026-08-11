-- Seed Admin User Script
-- Email: info@digitalrubix.com
-- Default Password: Abhijeet@1998 (hashed using bcrypt)

INSERT INTO users (name, email, password_hash, role)
VALUES (
    'Digital Rubix Admin',
    'info@digitalrubix.com',
    '$2y$12$qKGlm4ruHWKE3AJv4KFmzeG82b1eIBHzrLRAtEQJr82Gk6TTi5yZG',
    'super_admin'
) ON DUPLICATE KEY UPDATE email=VALUES(email);
