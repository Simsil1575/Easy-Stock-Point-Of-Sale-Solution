<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Premium Product Showcase</title>
      <link href="src/output.css" rel="stylesheet">    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Great+Vibes&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Pacifico&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Satisfy&display=swap" rel="stylesheet">
    <style>
        body, html {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }

        .slideshow {
            position: fixed;
            top: 0;
            right: 0;
            width: calc(100% - 250px);
            height: 100%;
            background: #ffffff;
        }

        .slide {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-size: contain;
            background-position: center;
            background-repeat: no-repeat;
            opacity: 0;
            transition: all 2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .slide.active {
            opacity: 1;
        }

        .price-tag {
            font-family: 'Playfair Display', serif;  /* Elegant serif */
            position: absolute;
            top: 3rem;
            right: 3rem;
            color: #ff69b4;
            text-shadow: 3px 3px 8px rgba(255,105,180,0.3);
            opacity: 0;
            transform: translateY(30px);
            transition: all 1.5s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 58px;
            font-weight: 400;
            letter-spacing: 1px;
        }

        .slide.active .price-tag {
            opacity: 1;
            transform: translateY(0);
        }
    </style>
</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">

    <div class="flex">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1">
            <div class="slideshow" id="slideshow">
                <?php
                $db = new PDO('sqlite:pos.db');
                $stmt = $db->query('SELECT image_url, price, name FROM products');
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach($products as $index => $product) {
                    $active = $index === 0 ? ' active' : '';
                    $width = 800;
                    $height = intval($width / 1.618);
                    
                    $formatted_price = 'N$' . number_format($product['price'], 2);
                    
                    echo "<div class='slide{$active}'>";
                    echo "<img src='products/{$product['image_url']}' 
                              style='width: {$width}px; height: {$height}px; 
                              object-fit: contain; 
                              position: absolute;
                              top: 50%;
                              left: 50%;
                              transform: translate(-50%, -50%) scale(1);
                              transition: all 1.5s cubic-bezier(0.4, 0, 0.2, 1)'>";
                    echo "<div class='price-tag'>{$formatted_price}</div>";
                    echo "</div>";
                }
                ?>
            </div>
        </div>
    </div>

    <script>
        const slides = document.querySelectorAll('.slide');
        let currentSlide = 0;

        function nextSlide() {
            // Smooth fade out current slide and price
            slides[currentSlide].style.opacity = '0';
            slides[currentSlide].querySelector('img').style.transform = 'translate(-50%, -50%) scale(0.95)';
            slides[currentSlide].querySelector('.price-tag').style.opacity = '0';
            slides[currentSlide].querySelector('.price-tag').style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                slides[currentSlide].classList.remove('active');
                currentSlide = (currentSlide + 1) % slides.length;
                
                // Smooth fade in next slide and price
                slides[currentSlide].classList.add('active');
                slides[currentSlide].style.opacity = '1';
                slides[currentSlide].querySelector('img').style.transform = 'translate(-50%, -50%) scale(1)';
                slides[currentSlide].querySelector('.price-tag').style.opacity = '1';
                slides[currentSlide].querySelector('.price-tag').style.transform = 'translateY(0)';
            }, 1500);
        }

        // Change slide every 6.18 seconds (golden ratio inspired timing)
        setInterval(nextSlide, 6180);
    </script>
</body>
</html>
