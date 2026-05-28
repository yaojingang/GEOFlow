import nextVitals from "eslint-config-next/core-web-vitals";

const eslintConfig = [
  {
    ignores: [
      "legacy/**",
      "node_modules.*/**",
      ".next.*/**",
      "public/js/filament/**",
      "public/css/filament/**",
      "public/fonts/filament/**",
      "vendor/**",
      "storage/**",
      "bootstrap/**",
      "resources/**",
    ],
  },
  ...nextVitals,
];

export default eslintConfig;
