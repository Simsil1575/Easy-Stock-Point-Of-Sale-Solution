BEGIN TRANSACTION;
CREATE TABLE IF NOT EXISTS "resetPasswords" (
	"id"	INTEGER,
	"code"	TEXT NOT NULL,
	"email"	TEXT NOT NULL,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "users" (
	"id"	INTEGER,
	"username"	TEXT NOT NULL UNIQUE,
	"password_hash"	TEXT NOT NULL,
	"role"	TEXT NOT NULL CHECK("role" IN ('cashier', 'manager', 'admin')),
	"email"	TEXT,
	"created_at"	TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS "users_new" (
	"id"	INTEGER,
	"username"	TEXT,
	"password_hash"	TEXT,
	"role"	TEXT CHECK("role" IN ('admin', 'manager', 'cashier')),
	"email"	TEXT,
	PRIMARY KEY("id")
);
INSERT INTO "resetPasswords" ("id","code","email") VALUES (1,'16769d88225bb6','cashier@gmail.com'),
 (2,'16769ed8808376','cashier@gmail.com'),
 (3,'1676a8b31d7b22','cashier@gmail.com'),
 (7,'1676a8d9c3f476','admin@gmail.com'),
 (9,'1676a8f0d3f6d0','simsiltechsolutions@gmail.com'),
 (11,'167730c3029f4a','simsiltechsolutions@gmail.com'),
 (12,'167c81b09603bf','simsiltechsolutions@gmail.com'),
 (13,'1681d197b0d7ac','simsiltechsolutions@gmail.com');
INSERT INTO "users" ("id","username","password_hash","role","email","created_at") VALUES (2,'Admin','3ce767854c5ef46dce6692817d2b4761','admin','medusallemfillemon@gmail.com','2025-03-05 10:00:59'),
 (4,'Manager','0965f1d872f54e7f94046fdce2649e8d','manager','','2025-03-05 10:12:29'),
 (5,'Fillemon','e3df4459c60cb43bf2bfa9bedf1b3cf9','admin','','2025-06-13 18:29:34'),
 (8,'Jacky','c6ef7ee9939951171d26b0312aeb5f86','manager','','2025-06-13 18:39:08'),
 (13,'Taleni','67faf5c55180e3ce7a49a8ef97fb6064','admin','','2025-06-13 18:44:11'),
 (15,'filly','67faf5c55180e3ce7a49a8ef97fb6064','admin','','2025-06-13 18:53:05'),
 (16,'FILLY','e3df4459c60cb43bf2bfa9bedf1b3cf9','manager','','2025-06-13 18:53:49'),
 (17,'Cashier','4bab44c1a05eaefb048496a94040407a','cashier','','2025-06-18 17:01:42'),
 (20,'ndinackie','664b8cdca1dcaddcd528312c7d406b09','admin','','2025-09-01 09:29:00'),
 (21,'G30rul3@90','5d552ba0ba0a3501c5bbee0791580ac4','admin','george.iiyambo@gmail.com','2025-09-01 10:25:54'),
 (22,'Ndinackie','664b8cdca1dcaddcd528312c7d406b09','manager','','2025-09-01 11:28:51'),
 (25,'Pena','4fdbd48d64147ff734fc9c45232fbe82','cashier','','2025-09-14 11:48:58'),
 (29,'taimi','e93028bdc1aacdfb3687181f2031765d','cashier','','2025-11-19 16:36:28'),
 (30,'tulonga','4a2a099cf53e837b3717086c6b332a49','cashier',NULL,'2025-12-06 16:57:05'),
 (31,'Salva','3c19211bcc34fabc8ec48e601e97e4a5','cashier',NULL,'2025-12-06 19:38:22'),
 (32,'OLIVIA','312351bff07989769097660a56395065','cashier',NULL,'2026-01-19 13:19:37');
COMMIT;
