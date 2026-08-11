-- Seed Admin User Script
-- Email: admin@digitalrubix.com
-- Default Password: Change@123 (hashed using bcrypt)

INSERT INTO users (name, email, password_hash, role)
VALUES (
    'Digital Rubix Admin',
    'admin@digitalrubix.com',
    '$2y$12$zP86.hN9a0v0B3jH2A.E.O0m9U7gT5l0s.Z4q.Q5v.R8w.S9t.U1u',
    'super_admin'
) ON DUPLICATE KEY UPDATE name=VALUES(name);
