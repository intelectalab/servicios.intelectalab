-- Empresa mínima para la prueba automática de GitHub: algunas pruebas de la consola de plataforma
-- leen "la primera empresa" con el usuario de la consola, que no ve lo creado dentro de cada prueba.
INSERT INTO tenants (code, name, legal_name, status, login_slug) VALUES ('ci-demo', 'Empresa CI', 'Empresa CI S.A.C.', 'ACTIVE', 'empresa-ci');
INSERT INTO companies (tenant_id, code, legal_name, tax_identifier)
  SELECT id, 'PRINCIPAL', 'Empresa CI S.A.C.', '20000000001' FROM tenants WHERE code = 'ci-demo';
