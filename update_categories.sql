-- SQL script to update product categories into consistent groups
-- Categories: Champagne, Wine, Beer, Cider, Vodka, Gin, Whiskey, Rum, Tequila, Liqueur, Shots, Soft Drinks, Juice, Water, Energy Drinks, Non-Alcoholic, Accessories
-- This script groups products into easy, consistent categories
-- Note: Transaction wrapper removed to avoid "transaction within transaction" errors

-- ============================================
-- CHAMPAGNE
-- ============================================
UPDATE products SET category = 'Champagne' WHERE id IN (835, 836, 837, 838, 1025, 1074, 1079);
UPDATE products SET category = 'Champagne' WHERE name LIKE '%GRAHAM BECK%';

-- ============================================
-- WINE
-- ============================================
UPDATE products SET category = 'Wine' WHERE id IN (841, 842, 843, 844, 845, 846, 848, 849, 850, 851, 854, 855, 864, 865, 866, 867, 1039, 1041, 1049, 1050, 1070, 1073, 1075, 1076, 1077, 1078, 1082);
UPDATE products SET category = 'Wine' WHERE name LIKE '%Rupert%Rothschild%' OR name LIKE '%Rupert & Roth%' AND id NOT IN (1032, 1078);
UPDATE products SET category = 'Wine' WHERE name LIKE '%Luc Belaire%' AND id = 839;

-- ============================================
-- BEER
-- ============================================
UPDATE products SET category = 'Beer' WHERE id IN (879, 880, 890, 881, 884, 885, 886, 887, 888, 1010, 1018, 1022, 1023, 1029, 1064);
UPDATE products SET category = 'Beer' WHERE name LIKE '%Corona%' OR name LIKE '%Heineken%' OR name LIKE '%Tafel%' OR name LIKE '%Castle%' OR name LIKE '%Carling%';
UPDATE products SET category = 'Beer' WHERE name LIKE '%Windhoek%' AND name NOT LIKE '%Non-Alc%' AND name NOT LIKE '%non alc%' AND name NOT LIKE '%Non-ALC%';
UPDATE products SET category = 'Beer' WHERE name LIKE '%Hansa%' OR name LIKE '%Flying Fish%' OR name LIKE '%Miller%';

-- ============================================
-- CIDER
-- ============================================
UPDATE products SET category = 'Cider' WHERE id IN (42, 43, 44, 45, 46, 47, 48, 49, 61, 1005, 1006, 1017, 1029, 1045, 1048, 1054, 1055, 1056, 1066, 1069);
UPDATE products SET category = 'Cider' WHERE name LIKE '%Strongbow%' OR name LIKE '%SAVANNA%' OR name LIKE '%Savannah%' OR name LIKE '%Brutal%';
UPDATE products SET category = 'Cider' WHERE name LIKE '%Bahama%' OR name LIKE '%CHILLERS%' OR name LIKE '%CARRIBEAN TWIST%';
UPDATE products SET category = 'Cider' WHERE name LIKE '%Hunters%' OR name LIKE '%Hunter%' OR name LIKE '%BERNINI%' OR name LIKE '%Bernini%' OR name LIKE '%Belgravia%';

-- ============================================
-- VODKA
-- ============================================
UPDATE products SET category = 'Vodka' WHERE id IN (910, 911, 912, 913, 914, 915, 916, 1067);
UPDATE products SET category = 'Vodka' WHERE name LIKE '%Cruz Vodka%' OR name LIKE '%Absolute Vodka%' OR name LIKE '%Ciroc%' OR name LIKE '%SMIRNOFF%';
UPDATE products SET category = 'Vodka' WHERE name LIKE '%Tango%' AND name NOT LIKE '%Shots%' AND name NOT LIKE '%shots%';

-- ============================================
-- GIN
-- ============================================
UPDATE products SET category = 'Gin' WHERE id IN (917, 918, 919, 920, 921, 922, 923, 924, 925, 1080);
UPDATE products SET category = 'Gin' WHERE name LIKE '%Gin%' AND name NOT LIKE '%Shots%' AND name NOT LIKE '%shots%' AND name NOT LIKE '%Malfy%Shots%';
UPDATE products SET category = 'Gin' WHERE name LIKE '%Malfy%' OR name LIKE '%Gordon%' OR name LIKE '%Bombay%' OR name LIKE '%Tanquray%' OR name LIKE '%Tanguray%';
UPDATE products SET category = 'Gin' WHERE name LIKE '%Inverache%' OR name LIKE '%Beefeater%' OR name LIKE '%DEAD MANS FINGER%';

-- ============================================
-- WHISKEY
-- ============================================
UPDATE products SET category = 'Whiskey' WHERE id IN (926, 927, 928, 929, 930, 931, 932, 933, 934, 935, 936, 937, 938, 939, 940, 941, 942, 943, 944, 945, 946, 947, 948, 949, 950, 951, 952, 953, 1026, 1072);
UPDATE products SET category = 'Whiskey' WHERE name LIKE '%Hennessy%' OR name LIKE '%Chivas%' OR name LIKE '%Glenmorange%' OR name LIKE '%Glefiddich%' OR name LIKE '%Glenfiddich%';
UPDATE products SET category = 'Whiskey' WHERE name LIKE '%Glelivet%' OR name LIKE '%Jonh Walker%' OR name LIKE '%Johnny Walker%' OR name LIKE '%John Walker%';
UPDATE products SET category = 'Whiskey' WHERE name LIKE '%Ballantine%' OR name LIKE '%Jameson%' OR name LIKE '%Jack Daniel%' OR name LIKE '%RICHELIEU%';
UPDATE products SET category = 'Whiskey' WHERE name LIKE '%Klipdrit%' OR name LIKE '%Gentlemen Jack%' OR name LIKE '%White Horse%' OR name LIKE '%Honor%';
UPDATE products SET category = 'Whiskey' WHERE name = 'Kwv 10 yrs';

-- ============================================
-- RUM
-- ============================================
UPDATE products SET category = 'Rum' WHERE id IN (1034);
UPDATE products SET category = 'Rum' WHERE name LIKE '%STROH RUM%' AND name NOT LIKE '%shots%' AND name NOT LIKE '%Shots%';

-- ============================================
-- TEQUILA
-- ============================================
UPDATE products SET category = 'Tequila' WHERE id IN (900);
UPDATE products SET category = 'Tequila' WHERE name = 'Tequila' OR (name LIKE '%Tequila%' AND name NOT LIKE '%Shots%' AND name NOT LIKE '%shots%');

-- ============================================
-- LIQUEUR
-- ============================================
UPDATE products SET category = 'Liqueur' WHERE id IN (899, 901, 902, 903, 904, 905, 906, 907, 908, 909, 1057, 1060);
UPDATE products SET category = 'Liqueur' WHERE name LIKE '%Jagermeister%' OR name LIKE '%Cactus Jack%' OR name LIKE '%PO-10-C%' OR name LIKE '%Peppermint%';
UPDATE products SET category = 'Liqueur' WHERE name LIKE '%Amarula%' OR name LIKE '%Strawberrylips%' OR name LIKE '%KAHLUA%' OR name LIKE '%Kalua%';
UPDATE products SET category = 'Liqueur' WHERE name LIKE '%Lime/ Passionfruit%' OR name LIKE '%Wild Africa%' OR name LIKE '%Malibu%' OR name LIKE '%SHANKY%';
UPDATE products SET category = 'Liqueur' WHERE name LIKE '%CARVO%' AND name NOT LIKE '%Shots%' AND name NOT LIKE '%shots%';

-- ============================================
-- SHOTS (all shot products)
-- ============================================
UPDATE products SET category = 'Shots' WHERE id IN (955, 956, 957, 958, 959, 960, 961, 962, 963, 964, 965, 966, 967, 968, 969, 970, 971, 972, 973, 974, 975, 976, 977, 978, 979, 980, 981, 982, 983, 984, 985, 986, 987, 988, 989, 990, 991, 992, 993, 994, 995, 996, 997, 998, 999, 1000, 1001, 1033, 1035, 1036, 1037, 1038, 1040, 1044, 1051, 1052, 1058, 1059, 1061, 1065, 1071, 1081);
UPDATE products SET category = 'Shots' WHERE name LIKE '%Shots%' OR name LIKE '%shots%' OR name LIKE '%SHOT%' OR name LIKE '%shot%' OR name LIKE '%TOT%';
UPDATE products SET category = 'Shots' WHERE name LIKE '%Blow Job%' OR name LIKE '%Springbokkie%' OR name LIKE '%KWV 10 years shot%';

-- ============================================
-- SOFT DRINKS
-- ============================================
UPDATE products SET category = 'Soft Drinks' WHERE id IN (896, 897, 893, 1063);
UPDATE products SET category = 'Soft Drinks' WHERE name LIKE '%Coke%' OR name LIKE '%Schweppes%' OR name LIKE '%Appletizer%' OR name LIKE '%red sq%';

-- ============================================
-- JUICE
-- ============================================
UPDATE products SET category = 'Juice' WHERE id IN (1020, 1021, 1031, 1043);
UPDATE products SET category = 'Juice' WHERE name LIKE '%Liqui Fruit%' OR name LIKE '%Liqui-Fruit%';

-- ============================================
-- WATER
-- ============================================
UPDATE products SET category = 'Water' WHERE id IN (898);
UPDATE products SET category = 'Water' WHERE name LIKE '%water%' OR name LIKE '%bonaqua%';

-- ============================================
-- ENERGY DRINKS
-- ============================================
UPDATE products SET category = 'Energy Drinks' WHERE id IN (892, 894);
UPDATE products SET category = 'Energy Drinks' WHERE name LIKE '%Redbull%' OR name LIKE '%Powerade%';

-- ============================================
-- NON-ALCOHOLIC BEVERAGES
-- ============================================
UPDATE products SET category = 'Non-Alcoholic' WHERE id IN (874, 889, 1011, 1030, 1062);
UPDATE products SET category = 'Non-Alcoholic' WHERE name LIKE '%Non-Alcoholic%' OR name LIKE '%non alc%' OR name LIKE '%Non-ALC%' OR name LIKE '%Clausthaler Original%';
UPDATE products SET category = 'Non-Alcoholic' WHERE name LIKE '%Windhoek%' AND (name LIKE '%Non-Alc%' OR name LIKE '%non alc%' OR name LIKE '%Non-ALC%');

-- ============================================
-- ACCESSORIES
-- ============================================
UPDATE products SET category = 'Accessories' WHERE id IN (1053);
UPDATE products SET category = 'Accessories' WHERE name LIKE '%Glass%' OR name LIKE '%glass%';

-- Verify categories (uncomment to run)
-- SELECT category, COUNT(*) as count FROM products GROUP BY category ORDER BY category;
