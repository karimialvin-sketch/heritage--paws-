-- =====================================================
-- Heritage Paws - Database Schema (MySQL / MySQL Workbench)
-- How to run: open MySQL Workbench > File > Open SQL Script
-- > select this file > click the lightning bolt (Execute).
-- =====================================================

DROP DATABASE IF EXISTS heritage_paws;
CREATE DATABASE heritage_paws CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE heritage_paws;

-- -----------------------------------------------------
-- USERS: admin, sellers and buyers share one table
-- status: sellers start as 'pending' until admin approves
-- -----------------------------------------------------
CREATE TABLE users (
    user_id        INT AUTO_INCREMENT PRIMARY KEY,
    full_name      VARCHAR(120)  NOT NULL,
    email          VARCHAR(150)  NOT NULL UNIQUE,
    phone          VARCHAR(25)   NOT NULL,
    password_hash  VARCHAR(255)  NOT NULL,
    role           ENUM('admin','seller','buyer') NOT NULL,
    location       VARCHAR(120)  DEFAULT NULL,
    kennel_name    VARCHAR(150)  DEFAULT NULL,   -- sellers only
    bio            TEXT          DEFAULT NULL,   -- sellers only
    status         ENUM('pending','active','suspended') NOT NULL DEFAULT 'active',
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_role_status (role, status)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- BREEDS: lookup table (admin can add more)
-- -----------------------------------------------------
CREATE TABLE breeds (
    breed_id   INT AUTO_INCREMENT PRIMARY KEY,
    breed_name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- DOGS: each listing belongs to one seller
-- listing_status: admin approves before it goes public
-- -----------------------------------------------------
CREATE TABLE dogs (
    dog_id          INT AUTO_INCREMENT PRIMARY KEY,
    seller_id       INT            NOT NULL,
    breed_id        INT            NOT NULL,
    name            VARCHAR(100)   NOT NULL,
    gender          ENUM('Male','Female') NOT NULL,
    age_months      INT            NOT NULL,
    price           DECIMAL(10,2)  NOT NULL,
    color           VARCHAR(60)    DEFAULT NULL,
    vaccinated      TINYINT(1)     NOT NULL DEFAULT 0,
    description     TEXT           NOT NULL,
    location        VARCHAR(120)   NOT NULL,
    availability    ENUM('available','reserved','sold') NOT NULL DEFAULT 'available',
    listing_status  ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(user_id)  ON DELETE CASCADE,
    FOREIGN KEY (breed_id)  REFERENCES breeds(breed_id),
    INDEX idx_public (listing_status, availability),
    INDEX idx_price (price)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- DOG_IMAGES: up to several photos per dog
-- -----------------------------------------------------
CREATE TABLE dog_images (
    image_id   INT AUTO_INCREMENT PRIMARY KEY,
    dog_id     INT          NOT NULL,
    file_name  VARCHAR(255) NOT NULL,
    is_primary TINYINT(1)   NOT NULL DEFAULT 0,
    FOREIGN KEY (dog_id) REFERENCES dogs(dog_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- INQUIRIES: the direct buyer -> seller link
-- seller replies inside the same row
-- -----------------------------------------------------
CREATE TABLE inquiries (
    inquiry_id  INT AUTO_INCREMENT PRIMARY KEY,
    dog_id      INT  NOT NULL,
    buyer_id    INT  NOT NULL,
    seller_id   INT  NOT NULL,
    message     TEXT NOT NULL,
    reply       TEXT DEFAULT NULL,
    status      ENUM('new','replied','closed') NOT NULL DEFAULT 'new',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    replied_at  TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (dog_id)    REFERENCES dogs(dog_id)   ON DELETE CASCADE,
    FOREIGN KEY (buyer_id)  REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (seller_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- FAVORITES: buyers save dogs they like
-- -----------------------------------------------------
CREATE TABLE favorites (
    buyer_id INT NOT NULL,
    dog_id   INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (buyer_id, dog_id),
    FOREIGN KEY (buyer_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (dog_id)   REFERENCES dogs(dog_id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- SEED DATA
-- =====================================================

-- Default admin  ->  email: admin@heritagepaws.com   password: Admin@123
-- CHANGE THIS PASSWORD after your first login.
INSERT INTO users (full_name, email, phone, password_hash, role, status)
VALUES ('Heritage Paws Admin', 'admin@heritagepaws.com', '0700000000',
        '$2y$10$JalZQsHDnZOoEXzltIWitu3M772d0cGiO4hl32CjSlQVhTbW/jW8S',
        'admin', 'active');

INSERT INTO breeds (breed_name) VALUES
('German Shepherd'), ('Rottweiler'), ('Labrador Retriever'), ('Golden Retriever'),
('Boerboel'), ('Siberian Husky'), ('Great Dane'), ('Doberman'),
('Cane Corso'), ('Pug'), ('Shih Tzu'), ('Chihuahua'), ('Mixed / Other');
