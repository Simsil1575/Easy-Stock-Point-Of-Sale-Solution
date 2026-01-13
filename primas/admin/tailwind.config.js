/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./*.php",    // PHP files in root directory
    "./src/**/*.php",  // PHP files in src directory
    "./src/**/*.{html,js}" // Other files
  ],
  theme: {
    extend: {},
  },
  plugins: [],
}

