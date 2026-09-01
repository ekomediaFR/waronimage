import type { Config } from "tailwindcss";

// Google Material palette, mapped onto the scales the components use.
// zinc = neutral surfaces/text (light), amber = Google blue accent (+ yellow chip shades),
// emerald/sky/red = Google green/blue/red tonal chips.
const config: Config = {
  content: [
    "./pages/**/*.{js,ts,jsx,tsx,mdx}",
    "./components/**/*.{js,ts,jsx,tsx,mdx}",
    "./app/**/*.{js,ts,jsx,tsx,mdx}",
  ],
  theme: {
    extend: {
      colors: {
        zinc: {
          50: "#202124",
          100: "#202124", // main text
          200: "#3c4043",
          300: "#3c4043", // secondary text
          400: "#5f6368", // muted text
          500: "#5f6368",
          600: "#80868b",
          700: "#dadce0", // input borders
          800: "#dadce0", // card borders / hovers
          900: "#ffffff", // surfaces
          950: "#f8f9fa", // page background
        },
        amber: {
          50: "#e8f0fe",
          100: "#e8f0fe",
          200: "#d2e3fc",
          300: "#b06000", // yellow-chip text
          400: "#1967d2", // links / hover blue
          500: "#1a73e8", // Google blue — primary accent
          600: "#1a73e8",
          700: "#fde293", // yellow-chip border
          800: "#fde293", // warning card border
          900: "#fef7e0",
          950: "#fef7e0", // yellow-chip / warning background
        },
        emerald: {
          50: "#e6f4ea",
          100: "#e6f4ea",
          200: "#188038",
          300: "#188038", // green-chip text
          400: "#188038",
          500: "#34a853",
          600: "#34a853", // bars
          700: "#ceead6", // green-chip border
          800: "#ceead6",
          900: "#e6f4ea",
          950: "#e6f4ea", // green-chip background
        },
        sky: {
          50: "#e8f0fe",
          100: "#e8f0fe",
          200: "#d2e3fc",
          300: "#1967d2", // blue-chip text
          400: "#1a73e8", // links
          500: "#1a73e8",
          600: "#1967d2",
          700: "#d2e3fc", // blue-chip border
          800: "#d2e3fc",
          900: "#e8f0fe",
          950: "#e8f0fe", // blue-chip background
        },
        red: {
          50: "#fce8e6",
          100: "#fce8e6",
          200: "#c5221f",
          300: "#c5221f", // red-chip text
          400: "#d93025", // error text
          500: "#d93025",
          600: "#d93025",
          700: "#fad2cf",
          800: "#fad2cf", // red-chip border
          900: "#fce8e6",
          950: "#fce8e6", // red-chip background
        },
      },
    },
  },
  plugins: [],
};
export default config;
