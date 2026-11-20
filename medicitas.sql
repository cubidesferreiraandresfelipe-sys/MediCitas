-- MediCitas · MySQL / MariaDB (XAMPP)
-- Importa este archivo en phpMyAdmin.

CREATE DATABASE IF NOT EXISTS medicitas
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE medicitas;

DROP TABLE IF EXISTS bitacora;
DROP TABLE IF EXISTS recordatorios;
DROP TABLE IF EXISTS citas;
DROP TABLE IF EXISTS descansos;
DROP TABLE IF EXISTS jornadas;
DROP TABLE IF EXISTS appointments;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS doctors;
DROP TABLE IF EXISTS specialties;

CREATE TABLE specialties (
  id    VARCHAR(20)  NOT NULL,
  name  VARCHAR(80)  NOT NULL,
  blurb VARCHAR(180) NOT NULL,
  price INT UNSIGNED NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE doctors (
  id           VARCHAR(20)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  specialty_id VARCHAR(20)  NOT NULL,
  years        TINYINT UNSIGNED NOT NULL,
  focus        VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_doctors_specialty (specialty_id),
  CONSTRAINT fk_doctors_specialty
    FOREIGN KEY (specialty_id) REFERENCES specialties (id)
) ENGINE=InnoDB;

CREATE TABLE users (
  id         CHAR(36) NOT NULL,
  name       VARCHAR(120) NOT NULL,
  email      VARCHAR(120) NOT NULL,
  cedula     VARCHAR(12) NOT NULL,
  celular    CHAR(10) NOT NULL,
  pass_hash  VARCHAR(255) NOT NULL,
  role       ENUM('paciente', 'administrativo', 'medico') NOT NULL DEFAULT 'paciente',
  doctor_id  VARCHAR(20) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_cedula (cedula),
  UNIQUE KEY uq_users_celular (celular),
  UNIQUE KEY uq_users_doctor (doctor_id),
  CONSTRAINT fk_users_doctor FOREIGN KEY (doctor_id) REFERENCES doctors (id)
) ENGINE=InnoDB;

CREATE TABLE appointments (
  id           CHAR(36) NOT NULL,
  code         CHAR(8)  NOT NULL,
  user_id      CHAR(36) NOT NULL,
  specialty_id VARCHAR(20) NOT NULL,
  doctor_id    VARCHAR(20) NOT NULL,
  appt_date    DATE NOT NULL,
  appt_time    CHAR(5) NOT NULL,
  nombre       VARCHAR(120) NOT NULL,
  cedula       VARCHAR(12)  NOT NULL,
  celular      CHAR(10)     NOT NULL,
  correo       VARCHAR(120) NOT NULL,
  motivo       VARCHAR(180) NOT NULL DEFAULT '',
  comentario   VARCHAR(500) NOT NULL DEFAULT '',
  status       ENUM('confirmada', 'cancelada') NOT NULL DEFAULT 'confirmada',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  slot_key     VARCHAR(40)
    GENERATED ALWAYS AS (
      CASE
        WHEN status = 'confirmada'
          THEN CONCAT(doctor_id, '|', CAST(appt_date AS CHAR), '|', appt_time)
        ELSE NULL
      END
    ) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_appointments_code (code),
  UNIQUE KEY uq_appointments_live_slot (slot_key),
  KEY idx_appointments_user (user_id),
  KEY idx_appointments_doctor_date (doctor_id, appt_date),
  CONSTRAINT fk_appointments_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_appointments_specialty FOREIGN KEY (specialty_id) REFERENCES specialties (id),
  CONSTRAINT fk_appointments_doctor FOREIGN KEY (doctor_id) REFERENCES doctors (id)
) ENGINE=InnoDB;

INSERT INTO specialties (id, name, blurb, price) VALUES
  ('general', 'Medicina general', 'Consulta, control y órdenes del día a día.', 65000),
  ('pedia',   'Pediatría',        'Crecimiento, vacunas y urgencias leves.', 80000),
  ('gine',    'Ginecología',      'Control, planificación y chequeo de rutina.', 95000),
  ('cardio',  'Cardiología',      'Presión, chequeo y seguimiento.', 120000),
  ('derma',   'Dermatología',     'Piel, lunares y tratamientos.', 90000),
  ('odonto',  'Odontología',      'Limpieza, dolor y revisión.', 75000),
  ('oftal',   'Oftalmología',     'Visión, fórmula y control.', 85000),
  ('orto',    'Ortopedia',        'Hueso, articulación y rehabilitación.', 110000);

INSERT INTO doctors (id, name, specialty_id, years, focus) VALUES
  ('d1', 'Dra. Laura Cifuentes', 'general', 12, 'Medicina familiar'),
  ('d2', 'Dr. Andrés Molina',     'general',  9, 'Control de crónicos'),
  ('d3', 'Dra. Camila Rincón',    'pedia',   11, 'Primera infancia'),
  ('d4', 'Dra. Valentina Rojas',  'gine',    15, 'Control ginecológico'),
  ('d5', 'Dr. Héctor Duarte',     'cardio',  18, 'Hipertensión'),
  ('d6', 'Dra. Mariana Peña',     'derma',    8, 'Piel sensible'),
  ('d7', 'Dr. Julián Acosta',     'odonto',  10, 'Dolor y operatoria'),
  ('d8', 'Dra. Sofía Beltrán',    'oftal',   14, 'Fórmula y control'),
  ('d9', 'Dr. Pablo Herrera',     'orto',    16, 'Rodilla y hombro');

-- Cuentas de los médicos. También se pueden crear desde el panel del equipo.
-- Contraseña de todas: 12345678
INSERT INTO users (id, name, email, cedula, celular, pass_hash, role, doctor_id) VALUES
  ('11111111-1111-4111-8111-111111111101', 'Dra. Laura Cifuentes', 'laura.cifuentes@medicitas.com', '52111001', '3101110001', '$2y$10$AU3Qe/y6Vok6xZhv4hxDreZNAYkw1MyZkLGjd2FGh9e90hv3Z.uqm', 'medico', 'd1'),
  ('11111111-1111-4111-8111-111111111102', 'Dr. Andrés Molina',     'andres.molina@medicitas.com',     '52111002', '3101110002', '$2y$10$AU3Qe/y6Vok6xZhv4hxDreZNAYkw1MyZkLGjd2FGh9e90hv3Z.uqm', 'medico', 'd2'),
  ('11111111-1111-4111-8111-111111111103', 'Dra. Camila Rincón',    'camila.rincon@medicitas.com',     '52111003', '3101110003', '$2y$10$AU3Qe/y6Vok6xZhv4hxDreZNAYkw1MyZkLGjd2FGh9e90hv3Z.uqm', 'medico', 'd3'),
  ('11111111-1111-4111-8111-111111111104', 'Dra. Valentina Rojas',  'valentina.rojas@medicitas.com',   '52111004', '3101110004', '$2y$10$AU3Qe/y6Vok6xZhv4hxDreZNAYkw1MyZkLGjd2FGh9e90hv3Z.uqm', 'medico', 'd4'),
  ('11111111-1111-4111-8111-111111111105', 'Dr. Héctor Duarte',     'hector.duarte@medicitas.com',     '52111005', '3101110005', '$2y$10$AU3Qe/y6Vok6xZhv4hxDreZNAYkw1MyZkLGjd2FGh9e90hv3Z.uqm', 'medico', 'd5'),
  ('11111111-1111-4111-8111-111111111106', 'Dra. Mariana Peña',     'mariana.pena@medicitas.com',      '52111006', '3101110006', '$2y$10$AU3Qe/y6Vok6xZhv4hxDreZNAYkw1MyZkLGjd2FGh9e90hv3Z.uqm', 'medico', 'd6'),
  ('11111111-1111-4111-8111-111111111107', 'Dr. Julián Acosta',     'julian.acosta@medicitas.com',     '52111007', '3101110007', '$2y$10$AU3Qe/y6Vok6xZhv4hxDreZNAYkw1MyZkLGjd2FGh9e90hv3Z.uqm', 'medico', 'd7'),
  ('11111111-1111-4111-8111-111111111108', 'Dra. Sofía Beltrán',    'sofia.beltran@medicitas.com',     '52111008', '3101110008', '$2y$10$AU3Qe/y6Vok6xZhv4hxDreZNAYkw1MyZkLGjd2FGh9e90hv3Z.uqm', 'medico', 'd8'),
  ('11111111-1111-4111-8111-111111111109', 'Dr. Pablo Herrera',     'pablo.herrera@medicitas.com',     '52111009', '3101110009', '$2y$10$AU3Qe/y6Vok6xZhv4hxDreZNAYkw1MyZkLGjd2FGh9e90hv3Z.uqm', 'medico', 'd9');
