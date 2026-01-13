# Product Category Classification

This document explains the consistent category structure used for products.

## Categories

### 1. **Champagne**
Premium sparkling wines from Champagne region
- Moët, Veuve Cliquot, Don Perignon, Graham Beck, etc.

### 2. **Wine**
All still wines (red, white, rosé)
- JC Le roux, Nederburg, Robertson, KWV, etc.

### 3. **Beer**
All beer products (lagers, ales, draught)
- Corona, Heineken, Castle Lite, Windhoek, Tafel, etc.

### 4. **Cider**
Cider and fruit-based alcoholic beverages
- Strongbow, Savanna, Hunters, Brutal Fruit, Bernini, etc.

### 5. **Vodka**
Vodka products
- Ciroc, Absolute Vodka, Cruz Vodka, Smirnoff, Tango, etc.

### 6. **Gin**
Gin products
- Gordon's, Bombay, Tanqueray, Beefeater, Malfy, etc.

### 7. **Whiskey**
All whiskey, scotch, and bourbon products
- Hennessy, Chivas, Glenfiddich, Johnnie Walker, Jameson, Jack Daniel's, etc.

### 8. **Rum**
Rum products
- Stroh Rum, etc.

### 9. **Tequila**
Tequila products
- Tequila, etc.

### 10. **Liqueur**
All liqueurs and flavored spirits
- Jagermeister, Amarula, Kahlua, Malibu, Cactus Jack, etc.

### 11. **Shots**
All shot-sized products (typically 25-100ml)
- Any product with "Shots", "shots", "SHOT", or "TOT" in the name

### 12. **Soft Drinks**
Carbonated soft drinks and mixers
- Coke, Schweppes, Appletizer, etc.

### 13. **Juice**
Fruit juices
- Liqui Fruit products, etc.

### 14. **Water**
Bottled water
- Bonaqua, etc.

### 15. **Energy Drinks**
Energy and sports drinks
- Red Bull, Powerade, etc.

### 16. **Non-Alcoholic**
Non-alcoholic beer alternatives
- Clausthaler, Windhoek Non-Alc, etc.

### 17. **Accessories**
Barware and accessories
- Wine glasses, etc.

## Usage

Run the `update_categories.sql` script to update all product categories in your database:

```sql
-- Execute the script
.read update_categories.sql
-- or
.source update_categories.sql
```

## Notes

- Categories are case-sensitive and use consistent naming
- Shot products are identified by keywords in the product name
- Some products may need manual review if they don't fit standard patterns
- The script uses both ID-based and pattern-based matching for accuracy







