# Mobile Sidebar Responsiveness Reference Guide

This document provides a comprehensive reference for implementing mobile sidebar responsiveness patterns used in `reports.php` and `credit-transactions.php`. Use this as a guide when applying these patterns to other files.

---

## Table of Contents
1. [Z-Index Hierarchy](#z-index-hierarchy)
2. [Hamburger Menu](#hamburger-menu)
3. [Mobile Sidebar Overlay](#mobile-sidebar-overlay)
4. [Header Row Structure](#header-row-structure)
5. [Mobile Table Styling](#mobile-table-styling)
6. [Mobile Pagination](#mobile-pagination)
7. [JavaScript Functions](#javascript-functions)
8. [Complete HTML Structure](#complete-html-structure)

---

## Z-Index Hierarchy

**CRITICAL:** Maintain this exact z-index order to prevent layering issues. The overlay MUST NOT overlap the sidebar.

```css
/* Z-Index Hierarchy (from highest to lowest) */
Hamburger Menu:     z-index: 10000  /* Highest - always accessible, matches credit-tabs.php */
Sidebar:            z-index: 9999   /* Above overlay, below hamburger (set in sidebar.php) */
Mobile Overlay:     z-index: 80     /* Below sidebar (9999) and hamburger (10000) - matches credit-tabs.php */
Sticky Header:     z-index: 50     /* Below sidebar (9999) and overlay (80) */
Notifications:     z-index: 20     /* Below header */
Content:            z-index: 1      /* Base layer */
```

**MANDATORY Implementation:**
```css
/* Hamburger - Highest priority */
.hamburger {
    z-index: 10000 !important; /* Highest - always accessible, matches credit-tabs.php */
}

/* Sidebar - MUST be above overlay */
.sidebar {
    z-index: 9999 !important; /* Ensure wrapper maintains z-index */
}

#sidebar {
    z-index: 9999 !important; /* Above overlay when open (set in sidebar.php style attribute) */
}

/* Mobile Overlay - MUST be below sidebar */
.mobile-overlay {
    z-index: 80 !important; /* Below sidebar (9999) and hamburger (10000) - matches credit-tabs.php */
}

.sticky.top-0 {
    z-index: 50;     /* Below sidebar (9999) and overlay (80) */
}
```

**⚠️ CRITICAL RULES:**
1. **ALWAYS** include both `.sidebar` and `#sidebar` z-index rules with `!important` to prevent overlay overlap
2. **NEVER** set overlay z-index higher than 80
3. **NEVER** set sidebar z-index lower than 9999
4. **ALWAYS** use `!important` for sidebar z-index rules to override any conflicting styles
5. The overlay z-index (80) MUST be lower than sidebar z-index (9999) - this is non-negotiable

---

## Hamburger Menu

### CSS Styles

```css
/* Mobile hamburger menu styles */
.hamburger {
    position: relative;
    width: 30px;
    height: 24px;
    cursor: pointer;
    z-index: 10000;  /* Always on top */
}

.hamburger span {
    display: block;
    position: absolute;
    height: 3px;
    width: 100%;
    background: rgb(0, 0, 0);
    border-radius: 2px;
    opacity: 1;
    left: 0;
    transform: rotate(0deg);
    transition: .25s ease-in-out;
}

.hamburger span:nth-child(1) {
    top: 0px;
}

.hamburger span:nth-child(2) {
    top: 10px;
}

.hamburger span:nth-child(3) {
    top: 20px;
}

/* Open state - transforms into X */
.hamburger.open span:nth-child(1) {
    top: 10px;
    transform: rotate(135deg);
}

.hamburger.open span:nth-child(2) {
    opacity: 0;
    left: -60px;
}

.hamburger.open span:nth-child(3) {
    top: 10px;
    transform: rotate(-135deg);
}
```

### HTML Structure

```html
<!-- Mobile Hamburger Menu Button -->
<div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
    <span></span>
    <span></span>
    <span></span>
</div>
```

**Key Points:**
- Use `lg:hidden` to show only on mobile/tablet
- Add background color class for visibility
- Three `<span>` elements create the hamburger icon
- `onclick="toggleSidebar()"` triggers the toggle function

---

## Mobile Sidebar Overlay

### CSS Styles

**⚠️ CRITICAL:** The overlay MUST have `z-index: 80` and sidebar MUST have `z-index: 9999` to prevent overlap.

```css
/* Mobile sidebar overlay */
.mobile-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 80 !important; /* MUST be below sidebar (9999) and hamburger (10000) */
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.mobile-overlay.active {
    opacity: 1;
    visibility: visible;
}

/* MANDATORY: Ensure sidebar maintains z-index above overlay */
.sidebar {
    z-index: 9999 !important; /* Prevent overlay from overlapping sidebar */
}

#sidebar {
    z-index: 9999 !important; /* Ensure sidebar stays above overlay */
}
```

### HTML Structure

```html
<!-- Mobile Sidebar Overlay -->
<div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
```

**Key Points:**
- Fixed position covers entire viewport
- **CRITICAL:** Must have `z-index: 80 !important` (lower than sidebar's 9999)
- Semi-transparent black background (0.5 opacity)
- Hidden by default, shown when sidebar is open
- Clicking overlay closes sidebar
- **MANDATORY:** Always include sidebar z-index rules (`.sidebar` and `#sidebar` with `z-index: 9999 !important`) to prevent overlap

---

## Header Row Structure

### Standard Header Pattern

```html
<!-- Header Row: Title + Controls -->
<div class="sticky top-0 z-50 bg-gray-50 py-4 mb-6 flex items-center justify-between gap-4 -mx-6 px-6 shadow-sm">
    <!-- Mobile Controls Row -->
    <div class="flex items-center gap-3">
        <!-- Mobile Hamburger Menu Button -->
        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
            <span></span>
            <span></span>
            <span></span>
        </div>
        <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold mb-0">Page Title</h1>
    </div>
    
    <!-- Right Side Controls (forms, buttons, etc.) -->
    <form method="POST" action="" class="flex items-center gap-2">
        <!-- Your controls here -->
    </form>
</div>
```

### Mobile-Specific Header Adjustments

```css
/* Mobile responsive adjustments */
@media (max-width: 1023px) {
    .content {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100vw !important;
        overflow-x: hidden;
    }
    
    .container {
        padding: 1rem;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }
    
    /* Fixed header on mobile */
    .sticky.top-0 {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        z-index: 1 !important;  /* Lower than sidebar/overlay */
        background-color: rgb(249 250 251) !important;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        width: 100% !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        padding-left: 1.5rem !important;
        padding-right: 1.5rem !important;
    }
    
    /* Add padding to content to account for fixed header */
    .container.mx-auto.p-6 {
        padding-top: calc(1.5rem + 100px) !important;
    }
}
```

**Key Points:**
- Header is sticky on desktop, fixed on mobile
- Negative margins (`-mx-6`) extend header to edges
- Padding compensates for fixed header on mobile
- Backdrop blur for modern glass effect

---

## Mobile Table Styling

### Vertical Card Layout Pattern

```css
/* Mobile Vertical Table Structure */
@media (max-width: 768px) {
    /* Remove overflow-x-auto on mobile */
    .overflow-x-auto {
        overflow-x: visible !important;
        width: 100% !important;
        max-width: 100% !important;
    }
    
    /* Ensure table containers don't overflow */
    .bg-white.rounded-lg,
    .table-container {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        overflow: hidden;
    }
    
    /* Ensure tables fit within container */
    table {
        width: 100% !important;
        max-width: 100% !important;
        table-layout: fixed;
        box-sizing: border-box;
    }
    
    /* Hide table headers on mobile */
    table thead {
        display: none;
    }
    
    /* Convert table rows to compact cards */
    table tbody tr {
        display: block;
        width: 100%;
        max-width: 100%;
        margin-bottom: 0.5rem;
        background: white;
        border: 2px solid #d1d5db;
        border-radius: 0.375rem;
        padding: 0.5rem;
        box-shadow: 0 2px 4px 0 rgba(0, 0, 0, 0.1);
        height: auto !important;
        position: relative;
        box-sizing: border-box;
    }
    
    /* Convert table cells to flex containers */
    table tbody td {
        display: flex;
        align-items: center;
        width: 100% !important;
        max-width: 100% !important;
        padding: 0.375rem 0.25rem !important;
        text-align: left !important;
        border: none !important;
        border-bottom: 1px solid #f3f4f6 !important;
        white-space: normal !important;
        overflow: visible !important;
        text-overflow: unset !important;
        height: auto !important;
        line-height: 1.3 !important;
        gap: 0.5rem;
        font-size: 0.8rem !important;
        color: #111827;
        box-sizing: border-box;
        word-wrap: break-word;
    }
    
    /* Remove border from last cell */
    table tbody td:last-child {
        border-bottom: none !important;
    }
    
    /* Add labels using data-label attribute */
    table tbody td::before {
        content: attr(data-label) ":";
        display: inline-block;
        font-weight: 600;
        font-size: 0.7rem;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.025em;
        min-width: 4rem;
        flex-shrink: 0;
    }
    
    /* Hide label if data-label is empty */
    table tbody td[data-label=""]::before {
        display: none;
    }
    
    /* Special handling for action columns */
    table tbody td[data-label="Actions"] {
        justify-content: center;
        padding: 0.5rem !important;
    }
    
    table tbody td[data-label="Actions"]::before {
        display: none; /* Hide label for Actions column */
    }
    
    /* Actions column buttons - wrap and stack */
    table tbody td[data-label="Actions"] > div {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        justify-content: center;
        align-items: center;
        width: 100%;
    }
    
    /* Remove hover effect on mobile cards */
    table tbody tr:hover {
        background: white;
    }
}
```

### HTML Table Structure with data-label

```html
<table class="w-full">
    <thead class="bg-gray-50">
        <tr>
            <th>Date</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td data-label="Date">2024-01-15</td>
            <td data-label="Amount">N$100.00</td>
            <td data-label="Status">
                <span class="badge">Paid</span>
            </td>
            <td data-label="Actions">
                <button>Edit</button>
            </td>
        </tr>
    </tbody>
</table>
```

**Key Points:**
- Each `<td>` must have `data-label` attribute matching the `<th>` text
- Headers are hidden on mobile (`display: none`)
- Rows become cards with labels generated from `data-label`
- Actions column can hide label and center buttons

---

## Mobile Pagination

### CSS Styles

```css
/* Mobile Pagination - Fit in one row */
@media (max-width: 768px) {
    .bg-gray-50.border-t {
        padding: 0.5rem 0.375rem !important;
        overflow-x: visible !important;
    }
    
    .bg-gray-50.border-t > div {
        flex-wrap: nowrap !important;
        gap: 0.25rem !important;
        align-items: center !important;
        width: 100% !important;
        min-width: 0 !important;
        overflow: visible !important;
    }
    
    /* Ensure parent containers don't restrict pagination */
    .bg-white.shadow-lg {
        overflow-x: visible !important;
    }
    
    /* Compact button groups */
    .bg-gray-50.border-t > div > div {
        display: flex !important;
        gap: 0.25rem !important;
        flex-shrink: 0;
    }
    
    /* First/Last buttons - icon only, smaller */
    .bg-gray-50.border-t button#firstPage,
    .bg-gray-50.border-t button#lastPage {
        padding: 0.375rem !important;
        min-width: 2rem !important;
        width: 2rem !important;
    }
    
    .bg-gray-50.border-t button#firstPage svg,
    .bg-gray-50.border-t button#lastPage svg {
        width: 1rem !important;
        height: 1rem !important;
        margin: 0 !important;
    }
    
    /* Prev/Next buttons - compact text */
    .bg-gray-50.border-t button#prevPage,
    .bg-gray-50.border-t button#nextPage {
        padding: 0.375rem 0.4rem !important;
        font-size: 0.65rem !important;
        min-width: auto !important;
        white-space: nowrap;
    }
    
    .bg-gray-50.border-t button#prevPage svg,
    .bg-gray-50.border-t button#nextPage svg {
        width: 0.875rem !important;
        height: 0.875rem !important;
    }
    
    /* Center section - compact and flexible */
    .bg-gray-50.border-t > div > div:nth-child(2) {
        flex-wrap: nowrap !important;
        gap: 0.25rem !important;
        flex-shrink: 1;
        min-width: 0;
        max-width: 100%;
        overflow: hidden;
    }
    
    /* Page number text - smaller and compact */
    .bg-gray-50.border-t span[id*="PageNumber"] {
        font-size: 0.65rem !important;
        white-space: nowrap;
        flex-shrink: 1;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 5rem;
    }
    
    /* Page input - compact */
    .bg-gray-50.border-t input[type="number"] {
        width: 2.5rem !important;
        padding: 0.375rem 0.375rem !important;
        font-size: 0.65rem !important;
        min-width: 2.5rem;
        max-width: 2.5rem;
    }
    
    /* Go button - compact */
    .bg-gray-50.border-t input[type="number"] + button {
        padding: 0.375rem 0.5rem !important;
        font-size: 0.65rem !important;
        white-space: nowrap;
    }
    
    /* All pagination buttons - consistent height */
    .bg-gray-50.border-t button {
        height: 2rem !important;
        min-height: 2rem !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
}
```

### HTML Pagination Structure

```html
<!-- Pagination Controls -->
<div class="px-6 py-2 bg-gray-50 border-t border-gray-200">
    <div class="flex justify-between items-center">
        <div class="flex gap-2">
            <button id="firstPage" class="...">
                <svg>...</svg>
            </button>
            <button id="prevPage" class="...">
                <svg>...</svg> Prev
            </button>
        </div>
        <div class="flex items-center gap-4">
            <span id="pageNumber" class="...">Page 1 of 1</span>
            <div class="flex items-center gap-2">
                <input type="number" id="pageInput" min="1" class="..." placeholder="Page">
                <button class="...">Go</button>
            </div>
        </div>
        <div class="flex gap-2">
            <button id="nextPage" class="...">
                Next <svg>...</svg>
            </button>
            <button id="lastPage" class="...">
                <svg>...</svg>
            </button>
        </div>
    </div>
</div>
```

**Key Points:**
- Three sections: left (first/prev), center (page info), right (next/last)
- Buttons shrink on mobile but remain functional
- Input field is compact (2.5rem width)
- All buttons have consistent height (2rem)

---

## JavaScript Functions

### Sidebar Toggle Functions

```javascript
// Mobile sidebar functions
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('mobileOverlay');
    const hamburger = document.querySelector('.hamburger');
    
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    hamburger.classList.toggle('open');
}

function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('mobileOverlay');
    const hamburger = document.querySelector('.hamburger');
    
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
    hamburger.classList.remove('open');
}
```

**Key Points:**
- `toggleSidebar()` - Opens/closes sidebar
- `closeSidebar()` - Explicitly closes sidebar
- Toggles three classes: `sidebar.open`, `overlay.active`, `hamburger.open`
- Overlay click also closes sidebar

---

## Complete HTML Structure

### Full Page Template

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Title</title>
    <link href="src/output.css" rel="stylesheet">
    <script src="src/jquery-3.6.0.min.js"></script>
    
    <style>
        /* Include all CSS from sections above */
        /* Hamburger, Overlay, Header, Table, Pagination styles */
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="container mx-auto p-6">
                <!-- Header Row: Title + Controls -->
                <div class="sticky top-0 z-50 bg-gray-50 py-4 mb-6 flex items-center justify-between gap-4 -mx-6 px-6 shadow-sm">
                    <!-- Mobile Controls Row -->
                    <div class="flex items-center gap-3">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold mb-0">Page Title</h1>
                    </div>
                    
                    <!-- Right Side Controls -->
                    <div class="flex items-center gap-2">
                        <!-- Your controls here -->
                    </div>
                </div>

                <!-- Main Content -->
                <div class="bg-white shadow-lg rounded-xl overflow-hidden">
                    <!-- Table or other content -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // Include JavaScript functions from section above
        function toggleSidebar() { /* ... */ }
        function closeSidebar() { /* ... */ }
    </script>
</body>
</html>
```

---

## Quick Checklist

When applying to a new file:

- [ ] Add hamburger menu CSS (z-index: 10000 !important)
- [ ] Add mobile overlay CSS (z-index: 80 !important)
- [ ] **MANDATORY:** Add sidebar z-index rules (`.sidebar` and `#sidebar` with z-index: 9999 !important)
- [ ] Verify z-index hierarchy: hamburger (10000) > sidebar (9999) > overlay (80)
- [ ] Add header row structure with hamburger button
- [ ] Add mobile overlay div in HTML
- [ ] Add JavaScript toggle functions
- [ ] Add mobile table styles (if using tables)
- [ ] Add mobile pagination styles (if using pagination)
- [ ] Add `data-label` attributes to all table cells
- [ ] Test z-index hierarchy (hamburger > sidebar > overlay > header)
- [ ] **VERIFY:** Overlay does NOT overlap sidebar when open
- [ ] Test on mobile viewport (max-width: 768px)

---

## Common Issues & Solutions

### Issue: Hamburger menu not clickable
**Solution:** Check z-index is 10000, ensure no parent has `pointer-events: none`

### Issue: Sidebar appears behind content
**Solution:** Verify sidebar z-index (9999, set in sidebar.php) is higher than content (1)

### Issue: Overlay overlaps sidebar
**Solution:** 
1. **MANDATORY:** Add both `.sidebar { z-index: 9999 !important; }` and `#sidebar { z-index: 9999 !important; }` rules
2. Ensure overlay has `z-index: 80 !important` (NOT higher)
3. Verify hamburger has `z-index: 10000 !important`
4. Check that no parent container is creating a new stacking context
5. Use `!important` to override any conflicting styles

### Issue: Table cells overflow on mobile
**Solution:** Add `box-sizing: border-box` and `max-width: 100%` to all table elements

### Issue: Pagination buttons too large on mobile
**Solution:** Apply mobile pagination styles with compact button sizes

### Issue: Header overlaps content on mobile
**Solution:** Add `padding-top` to container to account for fixed header height

---

## Notes

- Breakpoints: `1023px` for sidebar/content adjustments, `768px` for table/pagination
- Always use `!important` for mobile overrides to ensure they take precedence
- Test on actual mobile devices, not just browser dev tools
- Maintain consistent spacing and sizing across all mobile views

---

**Last Updated:** Based on patterns from `reports.php` and `credit-transactions.php`

