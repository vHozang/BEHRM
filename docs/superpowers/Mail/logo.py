svg_content = """<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 150" width="400" height="150">
  <defs>
    <style>
      .logo-text {
        font-family: 'Montserrat', 'Arial Black', sans-serif;
        font-size: 100px;
        font-weight: 900;
        letter-spacing: -2px;
      }
      .c-blue { fill: #1C55A5; }
      .d-orange { fill: #F17A28; }
      .n-green { fill: #52B34B; }
    </style>
  </defs>
  <!-- Transparent background -->
  <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" class="logo-text">
    <tspan class="c-blue">C</tspan><tspan class="d-orange">D</tspan><tspan class="n-green">N</tspan>
  </text>
</svg>"""

with open("logo_cdn.svg", "w", encoding="utf-8") as f:
    f.write(svg_content)

print("logo_cdn.svg created")