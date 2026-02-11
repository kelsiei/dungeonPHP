-- CREATE DATABASE
CREATE DATABASE IF NOT EXISTS game_db;
USE game_db; 

-- CREATE TABLE: player
CREATE TABLE IF NOT EXISTS player (
    id INT AUTO_INCREMENT PRIMARY KEY,
    location VARCHAR(50) NOT NULL DEFAULT 'start',
    health INT NOT NULL DEFAULT 100
);

-- CREATE TABLE: inventory
CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(50) NOT NULL UNIQUE
);

-- CREATE TABLE: command_log
CREATE TABLE IF NOT EXISTS command_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    command_text VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ensure we've one player in the game when we start

INSERT INTO player (location, health)
SELECT 'start', 100
WHERE NOT EXISTS (SELECT 1 FROM player);