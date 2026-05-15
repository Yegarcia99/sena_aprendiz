-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 14-04-2026 a las 04:19:55
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `sena_aprendices`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `acciones_remediales`
--

CREATE TABLE `acciones_remediales` (
  `id` int(11) NOT NULL,
  `pendiente_id` int(11) NOT NULL,
  `instructor_id` int(11) NOT NULL COMMENT 'Instructor que realizó la acción',
  `fecha_accion` date NOT NULL,
  `tipo_accion` enum('Refuerzo presencial','Tutoría individual','Taller compensatorio','Trabajo práctico','Evaluación oral','Otro') NOT NULL,
  `descripcion` text NOT NULL,
  `resultado` enum('Aprobado','No aprobado','En proceso') DEFAULT 'En proceso',
  `novedad_aprobacion` tinyint(1) DEFAULT 0 COMMENT 'Si el instructor registró novedad de aprobación',
  `observaciones` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `firma_instructor` mediumtext DEFAULT NULL,
  `firma_aprendiz` mediumtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `acciones_remediales`
--

INSERT INTO `acciones_remediales` (`id`, `pendiente_id`, `instructor_id`, `fecha_accion`, `tipo_accion`, `descripcion`, `resultado`, `novedad_aprobacion`, `observaciones`, `created_at`, `firma_instructor`, `firma_aprendiz`) VALUES
(2, 1, 1, '2026-03-31', 'Taller compensatorio', 'gfhg', 'En proceso', 0, 'hfgh', '2026-04-11 21:53:46', NULL, NULL),
(3, 2, 2, '2026-04-12', 'Refuerzo presencial', 'vfvf', 'En proceso', 0, 'fvf', '2026-04-11 22:21:24', NULL, NULL),
(4, 3, 3, '2026-04-12', 'Tutoría individual', 'jt', 'En proceso', 0, 'jyjyj', '2026-04-11 22:23:56', NULL, NULL),
(5, 4, 3, '2026-04-12', 'Evaluación oral', 'jtjyjtyjtyj', 'En proceso', 0, 'yjtyj', '2026-04-11 22:24:37', NULL, NULL),
(6, 5, 1, '2026-04-14', 'Refuerzo presencial', 'Paz y salvo', 'Aprobado', 0, 'Paz y salvo', '2026-04-13 23:47:39', NULL, NULL),
(7, 5, 2, '2026-04-14', 'Refuerzo presencial', 'Se verifica accion registrada por el profesor', 'Aprobado', 1, 'Se verifica accion registrada por el profesor', '2026-04-14 00:24:07', NULL, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `aprendices`
--

CREATE TABLE `aprendices` (
  `id` int(11) NOT NULL,
  `nombres` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `documento` varchar(20) NOT NULL,
  `tipo_documento` enum('CC','TI','CE','Pasaporte') DEFAULT 'CC',
  `email` varchar(120) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `ficha_id` int(11) NOT NULL,
  `estado` enum('Activo','Retiro Voluntario','Cancelado','Aplazado','Egresado') DEFAULT 'Activo',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `aprendices`
--

INSERT INTO `aprendices` (`id`, `nombres`, `apellidos`, `documento`, `tipo_documento`, `email`, `telefono`, `ficha_id`, `estado`, `created_at`) VALUES
(1, 'Yeral Esmid', 'Garcia Lancheros', '1060652628', 'CC', 'yegarcia9910@gmail.com', '3104290689', 1, 'Activo', '2026-04-11 20:32:43'),
(2, 'Camilo', 'Escobar', '1060652688', 'CC', 'camilo@gmail.com', '3103745234', 2, 'Activo', '2026-04-11 22:20:09'),
(3, 'Andres Felipe', 'orozco', '123456', 'CC', 'andres@gmail.com', '89000000', 1, 'Activo', '2026-04-13 23:32:18');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `comite_aprendices`
--

CREATE TABLE `comite_aprendices` (
  `id` int(11) NOT NULL,
  `aprendiz_id` int(11) NOT NULL,
  `fecha_remision` date NOT NULL,
  `motivo_remision` text NOT NULL,
  `decision` enum('Continúa','Aplaza','Retira','Pendiente') DEFAULT 'Pendiente',
  `observaciones_comite` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `comite_aprendices`
--

INSERT INTO `comite_aprendices` (`id`, `aprendiz_id`, `fecha_remision`, `motivo_remision`, `decision`, `observaciones_comite`, `created_at`) VALUES
(2, 3, '2026-04-14', 'No presenta evidencias', 'Pendiente', 'No presenta evidencias', '2026-04-13 23:56:49');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `competencias`
--

CREATE TABLE `competencias` (
  `id` int(11) NOT NULL,
  `nombre` varchar(250) NOT NULL,
  `codigo` varchar(30) DEFAULT NULL,
  `programa_id` int(11) NOT NULL,
  `trimestre` tinyint(4) NOT NULL COMMENT 'Trimestre en que normalmente se dicta',
  `horas` int(11) DEFAULT 0,
  `activa` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `competencias`
--

INSERT INTO `competencias` (`id`, `nombre`, `codigo`, `programa_id`, `trimestre`, `horas`, `activa`) VALUES
(1, 'Matematicas', '01', 1, 1, 50, 1),
(2, 'Ingles', '02', 1, 2, 50, 1),
(3, 'Construccion de software', '228018', 1, 6, 1008, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `fichas`
--

CREATE TABLE `fichas` (
  `id` int(11) NOT NULL,
  `numero_ficha` varchar(20) NOT NULL,
  `programa_id` int(11) NOT NULL,
  `gestor_id` int(11) DEFAULT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin_lectiva` date DEFAULT NULL,
  `jornada` enum('Diurna','Nocturna','Madrugada','Mixta') DEFAULT 'Diurna',
  `activa` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `fichas`
--

INSERT INTO `fichas` (`id`, `numero_ficha`, `programa_id`, `gestor_id`, `fecha_inicio`, `fecha_fin_lectiva`, `jornada`, `activa`, `created_at`) VALUES
(1, '2929061', 1, 5, '2025-02-01', '2026-06-30', 'Nocturna', 1, '2026-04-11 20:32:07'),
(2, '2929062', 2, NULL, '2025-07-11', '2026-04-11', 'Diurna', 1, '2026-04-11 21:56:47'),
(3, '3390374', 1, 6, '2025-05-13', '2027-06-15', 'Diurna', 1, '2026-04-14 01:18:10');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ficha_instructores`
--

CREATE TABLE `ficha_instructores` (
  `ficha_id` int(11) NOT NULL,
  `instructor_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `ficha_instructores`
--

INSERT INTO `ficha_instructores` (`ficha_id`, `instructor_id`) VALUES
(1, 5),
(3, 1),
(3, 2),
(3, 3),
(3, 4),
(3, 5);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `instructores`
--

CREATE TABLE `instructores` (
  `id` int(11) NOT NULL,
  `nombres` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `documento` varchar(20) NOT NULL,
  `email` varchar(120) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `tipo` enum('Planta','Contrato') DEFAULT 'Planta',
  `activo` tinyint(1) DEFAULT 1,
  `usuario_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `instructores`
--

INSERT INTO `instructores` (`id`, `nombres`, `apellidos`, `documento`, `email`, `telefono`, `tipo`, `activo`, `usuario_id`, `created_at`) VALUES
(1, 'Carlos Alberto', 'Martínez López', '12345678', 'carlos.martinez@sena.edu.co', NULL, 'Planta', 1, NULL, '2026-04-10 11:35:49'),
(2, 'María Fernanda', 'Gómez Ruiz', '23456789', 'maria.gomez@sena.edu.co', NULL, 'Planta', 1, NULL, '2026-04-10 11:35:49'),
(3, 'Juan Sebastián', 'Torres Vargas', '34567890', 'juan.torres@sena.edu.co', NULL, 'Contrato', 1, NULL, '2026-04-10 11:35:49'),
(4, 'Jeferson', 'Rodrigues', '1060652618', 'jeferson@gmail.com', '3103745234', 'Planta', 1, 7, '2026-04-14 01:11:50'),
(5, 'Jose German', 'Estrada', '1053784000', 'bra@gmail.com', '89012345', 'Planta', 1, 8, '2026-04-14 01:15:30');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pendientes_aprendices`
--

CREATE TABLE `pendientes_aprendices` (
  `id` int(11) NOT NULL,
  `aprendiz_id` int(11) NOT NULL,
  `competencia_id` int(11) NOT NULL,
  `resultado_id` int(11) DEFAULT NULL,
  `instructor_id` int(11) NOT NULL,
  `trimestre_ocurrencia` tinyint(4) NOT NULL,
  `fecha_registro` date NOT NULL,
  `motivo` text DEFAULT NULL,
  `debe_repetir_competencia` tinyint(1) DEFAULT 0,
  `estado` enum('Pendiente','En proceso','Superado','Remitido a comité') DEFAULT 'Pendiente',
  `observaciones` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `pendientes_aprendices`
--

INSERT INTO `pendientes_aprendices` (`id`, `aprendiz_id`, `competencia_id`, `resultado_id`, `instructor_id`, `trimestre_ocurrencia`, `fecha_registro`, `motivo`, `debe_repetir_competencia`, `estado`, `observaciones`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 1, 1, '2026-03-11', 'Aprendiz tiene pendiente por pasar la competencia ya que no paso el resultado de aprendizaje', 0, 'Pendiente', '', '2026-04-11 21:43:56', '2026-04-11 21:43:56'),
(2, 2, 2, 3, 3, 3, '2026-04-08', 'ff', 0, 'Pendiente', 'f', '2026-04-11 22:20:38', '2026-04-11 22:20:38'),
(3, 1, 2, 3, 2, 3, '2026-04-12', 'hygy', 0, 'Remitido a comité', '', '2026-04-11 22:22:34', '2026-04-11 22:38:34'),
(4, 1, 1, 2, 3, 5, '2026-04-12', 'yrjt', 0, 'Superado', 'tyj', '2026-04-11 22:23:17', '2026-04-11 22:38:11'),
(5, 3, 3, 8, 1, 8, '2026-04-14', 'No presento evidencia', 0, 'Superado', 'No presento evidencia', '2026-04-13 23:38:05', '2026-04-14 00:40:05');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pendiente_resultados`
--

CREATE TABLE `pendiente_resultados` (
  `pendiente_id` int(11) NOT NULL,
  `resultado_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `programas`
--

CREATE TABLE `programas` (
  `id` int(11) NOT NULL,
  `nombre` varchar(200) NOT NULL,
  `codigo` varchar(20) NOT NULL,
  `nivel` enum('Técnico','Tecnólogo','Especialización') DEFAULT 'Técnico',
  `duracion_trimestres` tinyint(4) DEFAULT 6,
  `activo` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `programas`
--

INSERT INTO `programas` (`id`, `nombre`, `codigo`, `nivel`, `duracion_trimestres`, `activo`, `created_at`) VALUES
(1, 'Análisis y Desarrollo de Software', '228106', 'Tecnólogo', 6, 1, '2026-04-10 11:35:49'),
(2, 'Gestión Empresarial', '122154', 'Técnico', 4, 1, '2026-04-10 11:35:49');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `resultados_aprendizaje`
--

CREATE TABLE `resultados_aprendizaje` (
  `id` int(11) NOT NULL,
  `competencia_id` int(11) NOT NULL,
  `nombre` varchar(300) NOT NULL,
  `codigo` varchar(30) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `resultados_aprendizaje`
--

INSERT INTO `resultados_aprendizaje` (`id`, `competencia_id`, `nombre`, `codigo`, `activo`) VALUES
(1, 1, 'Suma y resta de fracciones', '01', 1),
(2, 1, 'Multiplicacion y Division de fracciones', '02', 1),
(3, 2, 'pasado simple', NULL, 1),
(4, 3, '01. Planear actividades', '1096', 1),
(5, 3, '02. contruir base', '1096', 1),
(6, 3, '03.Crear componentes', '1096', 1),
(7, 3, '04.Codificar el software', '1096', 1),
(8, 3, '05. Realizar pruebas', '1096', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `username` varchar(60) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `nombres` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `email` varchar(120) DEFAULT NULL,
  `rol` enum('Administrador','Coordinador','Gestor','Instructor') DEFAULT 'Gestor',
  `activo` tinyint(1) DEFAULT 1,
  `debe_cambiar_pass` tinyint(1) DEFAULT 0,
  `ultimo_acceso` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `username`, `password_hash`, `nombres`, `apellidos`, `email`, `rol`, `activo`, `debe_cambiar_pass`, `ultimo_acceso`, `created_at`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrador', 'SENA', 'admin@sena.edu.co', 'Administrador', 1, 0, '2026-04-13 20:28:46', '2026-04-10 11:35:49'),
(2, 'coordinador', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Coordinador', 'Académico', 'coordinacion@sena.edu.co', 'Coordinador', 1, 0, NULL, '2026-04-10 11:35:49'),
(3, 'yegarcia', '$2y$10$4Nn2zvPOh2psZWGcak4t7u01mUA4RBYnxBM8ExWK1vxkKscSzz5Ki', 'Yeral Esmid', 'Garcia Lancheros', 'yer@gmail.com', 'Administrador', 1, 0, '2026-04-13 18:15:04', '2026-04-13 23:14:42'),
(5, 'Cloaiza', '$2y$10$j14JcWCG0PdzaaADAwxZye9/P27UHy7ujYLz5F0fqAkNMTuTFv0dG', 'Carlos', 'Loaiza', '', 'Gestor', 1, 0, '2026-04-13 21:17:45', '2026-04-13 23:30:53'),
(6, 'Gestrada', '$2y$10$lci4pgmHDODCOa8IgwuuIeho0z0KiWG31TgRAdhPAZqiqRAdUi3Eu', 'German', 'Estrada', '', 'Gestor', 1, 0, '2026-04-13 18:40:52', '2026-04-13 23:40:38'),
(7, '1060652618', '$2y$10$IqW0YuhltgWNmvW7DPoHTuM836tyYZLug2nP6Tkun9KQgyRWvBzLm', 'Jeferson', 'Rodrigues', 'jeferson@gmail.com', 'Instructor', 1, 1, '2026-04-13 20:12:08', '2026-04-14 01:11:50'),
(8, '1053784000', '$2y$10$7BM3UkzWCBufoVtezbKIEOS.0C2CkcRuDCtiBbhZ15jQ4/Ultk.lW', 'Jose German', 'Estrada', 'bra@gmail.com', 'Instructor', 1, 0, '2026-04-13 21:17:10', '2026-04-14 01:15:30');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `acciones_remediales`
--
ALTER TABLE `acciones_remediales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pendiente_id` (`pendiente_id`),
  ADD KEY `instructor_id` (`instructor_id`);

--
-- Indices de la tabla `aprendices`
--
ALTER TABLE `aprendices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `documento` (`documento`),
  ADD KEY `ficha_id` (`ficha_id`);

--
-- Indices de la tabla `comite_aprendices`
--
ALTER TABLE `comite_aprendices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `aprendiz_id` (`aprendiz_id`);

--
-- Indices de la tabla `competencias`
--
ALTER TABLE `competencias`
  ADD PRIMARY KEY (`id`),
  ADD KEY `programa_id` (`programa_id`);

--
-- Indices de la tabla `fichas`
--
ALTER TABLE `fichas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `numero_ficha` (`numero_ficha`),
  ADD KEY `programa_id` (`programa_id`),
  ADD KEY `fk_fichas_gestor` (`gestor_id`);

--
-- Indices de la tabla `ficha_instructores`
--
ALTER TABLE `ficha_instructores`
  ADD PRIMARY KEY (`ficha_id`,`instructor_id`),
  ADD KEY `instructor_id` (`instructor_id`);

--
-- Indices de la tabla `instructores`
--
ALTER TABLE `instructores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `documento` (`documento`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `usuario_id` (`usuario_id`);

--
-- Indices de la tabla `pendientes_aprendices`
--
ALTER TABLE `pendientes_aprendices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `aprendiz_id` (`aprendiz_id`),
  ADD KEY `competencia_id` (`competencia_id`),
  ADD KEY `resultado_id` (`resultado_id`),
  ADD KEY `instructor_id` (`instructor_id`);

--
-- Indices de la tabla `pendiente_resultados`
--
ALTER TABLE `pendiente_resultados`
  ADD PRIMARY KEY (`pendiente_id`,`resultado_id`),
  ADD KEY `resultado_id` (`resultado_id`);

--
-- Indices de la tabla `programas`
--
ALTER TABLE `programas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codigo` (`codigo`);

--
-- Indices de la tabla `resultados_aprendizaje`
--
ALTER TABLE `resultados_aprendizaje`
  ADD PRIMARY KEY (`id`),
  ADD KEY `competencia_id` (`competencia_id`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `acciones_remediales`
--
ALTER TABLE `acciones_remediales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `aprendices`
--
ALTER TABLE `aprendices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `comite_aprendices`
--
ALTER TABLE `comite_aprendices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `competencias`
--
ALTER TABLE `competencias`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `fichas`
--
ALTER TABLE `fichas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `instructores`
--
ALTER TABLE `instructores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `pendientes_aprendices`
--
ALTER TABLE `pendientes_aprendices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `programas`
--
ALTER TABLE `programas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `resultados_aprendizaje`
--
ALTER TABLE `resultados_aprendizaje`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `acciones_remediales`
--
ALTER TABLE `acciones_remediales`
  ADD CONSTRAINT `acciones_remediales_ibfk_1` FOREIGN KEY (`pendiente_id`) REFERENCES `pendientes_aprendices` (`id`),
  ADD CONSTRAINT `acciones_remediales_ibfk_2` FOREIGN KEY (`instructor_id`) REFERENCES `instructores` (`id`);

--
-- Filtros para la tabla `aprendices`
--
ALTER TABLE `aprendices`
  ADD CONSTRAINT `aprendices_ibfk_1` FOREIGN KEY (`ficha_id`) REFERENCES `fichas` (`id`);

--
-- Filtros para la tabla `comite_aprendices`
--
ALTER TABLE `comite_aprendices`
  ADD CONSTRAINT `comite_aprendices_ibfk_1` FOREIGN KEY (`aprendiz_id`) REFERENCES `aprendices` (`id`);

--
-- Filtros para la tabla `competencias`
--
ALTER TABLE `competencias`
  ADD CONSTRAINT `competencias_ibfk_1` FOREIGN KEY (`programa_id`) REFERENCES `programas` (`id`);

--
-- Filtros para la tabla `fichas`
--
ALTER TABLE `fichas`
  ADD CONSTRAINT `fichas_ibfk_1` FOREIGN KEY (`programa_id`) REFERENCES `programas` (`id`),
  ADD CONSTRAINT `fk_fichas_gestor` FOREIGN KEY (`gestor_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `ficha_instructores`
--
ALTER TABLE `ficha_instructores`
  ADD CONSTRAINT `ficha_instructores_ibfk_1` FOREIGN KEY (`ficha_id`) REFERENCES `fichas` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ficha_instructores_ibfk_2` FOREIGN KEY (`instructor_id`) REFERENCES `instructores` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `instructores`
--
ALTER TABLE `instructores`
  ADD CONSTRAINT `fk_ins_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `pendientes_aprendices`
--
ALTER TABLE `pendientes_aprendices`
  ADD CONSTRAINT `pendientes_aprendices_ibfk_1` FOREIGN KEY (`aprendiz_id`) REFERENCES `aprendices` (`id`),
  ADD CONSTRAINT `pendientes_aprendices_ibfk_2` FOREIGN KEY (`competencia_id`) REFERENCES `competencias` (`id`),
  ADD CONSTRAINT `pendientes_aprendices_ibfk_3` FOREIGN KEY (`resultado_id`) REFERENCES `resultados_aprendizaje` (`id`),
  ADD CONSTRAINT `pendientes_aprendices_ibfk_4` FOREIGN KEY (`instructor_id`) REFERENCES `instructores` (`id`);

--
-- Filtros para la tabla `pendiente_resultados`
--
ALTER TABLE `pendiente_resultados`
  ADD CONSTRAINT `pendiente_resultados_ibfk_1` FOREIGN KEY (`pendiente_id`) REFERENCES `pendientes_aprendices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pendiente_resultados_ibfk_2` FOREIGN KEY (`resultado_id`) REFERENCES `resultados_aprendizaje` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `resultados_aprendizaje`
--
ALTER TABLE `resultados_aprendizaje`
  ADD CONSTRAINT `resultados_aprendizaje_ibfk_1` FOREIGN KEY (`competencia_id`) REFERENCES `competencias` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
