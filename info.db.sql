BEGIN TRANSACTION;
CREATE TABLE IF NOT EXISTS "business_info" (
	"id"	INTEGER,
	"name"	TEXT NOT NULL,
	"location"	TEXT NOT NULL,
	"phone"	TEXT,
	"footer_text"	TEXT,
	"printer_port"	TEXT NOT NULL DEFAULT 'COM4',
	"closing_time"	TEXT NOT NULL DEFAULT '00:00',
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE TABLE IF NOT EXISTS inbox_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, message TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, is_read INTEGER DEFAULT 0);
INSERT INTO "business_info" ("id","name","location","phone","footer_text","printer_port","closing_time") VALUES (1,'Is 2 Do Bar','Oshakati, Namibia','+264812772340','Much Appreciated!!','COM4','02:00');
INSERT INTO "inbox_messages" ("id","message","created_at","is_read") VALUES (150,'Alert: Dunhill Kingsize Omakaya cigarette sales are consistently paired with Windhoek Draught. Explore bundling opportunities to boost overall transaction value.','2025-06-08 18:56:43',0),
 (151,'Alert: Dunhill Kingsize Omakaya cigarette sales are consistently paired with Windhoek Draught & Tafel Lager. Consider bundling for increased revenue!','2025-06-08 19:00:31',0),
 (152,'Warning: Dunhill Kingsize Omakaya cigarette stock is low (45). High purchase frequency detected. Consider reordering immediately to avoid lost sales.','2025-06-08 19:01:00',0),
 (153,'Warning: Dunhill Kingsize Ciggarettes Omakaya are frequently purchased with Windhoek Draught. Consider bundling or promotional offers to boost sales.','2025-06-08 19:01:25',0),
 (154,'Alert: Dunhill Kingsize Omakaya cigarettes are frequently purchased with Windhoek Draught. Consider a combo deal to boost sales!','2025-06-08 19:01:30',0),
 (155,'Warning: Dunhill Kingsize Omakaya stock is low (45). High sales volume suggests immediate reorder to avoid lost revenue.','2025-06-08 19:06:34',0);
COMMIT;
