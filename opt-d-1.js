function drawBreachChart(canvasId, labels, values, delta) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !labels || labels.length === 0) return;
 
    const ctx   = canvas.getContext("2d");
    const W     = canvas.width;
    const H     = canvas.height;
 
    // Layout constants
    const PAD_LEFT   = 52;  // room for y-axis labels
    const PAD_RIGHT  = 16;
    const PAD_TOP    = 16;
    const PAD_BOTTOM = 56;  // room for x-axis labels
 
    const chartW = W - PAD_LEFT - PAD_RIGHT;
    const chartH = H - PAD_TOP  - PAD_BOTTOM;
 
    // Scale
    const maxVal  = Math.max(...values, delta, 1);
    const toY     = v  => PAD_TOP + chartH - (v / maxVal) * chartH;
    const barW    = Math.max(4, Math.floor(chartW / labels.length) - 4);
    const barStep = chartW / labels.length;
    const barX    = i  => PAD_LEFT + i * barStep + (barStep - barW) / 2;
 
    ctx.clearRect(0, 0, W, H);
 
    // ── Y-axis gridlines + labels ─────────────────────────────────────────
    ctx.strokeStyle = "#e2e8f0";
    ctx.lineWidth   = 1;
    ctx.fillStyle   = "#718096";
    ctx.font        = "11px Inter, sans-serif";
    ctx.textAlign   = "right";
 
    const tickCount = 5;
    for (let t = 0; t <= tickCount; t++) {
        const v  = (maxVal / tickCount) * t;
        const y  = toY(v);
        ctx.beginPath();
        ctx.moveTo(PAD_LEFT, y);
        ctx.lineTo(PAD_LEFT + chartW, y);
        ctx.stroke();
        ctx.fillText(Math.round(v), PAD_LEFT - 6, y + 4);
    }
 
    // ── Bars ─────────────────────────────────────────────────────────────
    values.forEach((v, i) => {
    const x      = barX(i);
    const yTop   = toY(v);
    const yBase  = toY(0);
    const height = yBase - yTop;

    // FIX: Changed > to >= so hitting the threshold exactly triggers red
    ctx.fillStyle = (v >= delta) ? "#e53e3e" : "#3182ce"; 
    
    ctx.beginPath();
    ctx.roundRect(x, yTop, barW, Math.max(height, 1), [3, 3, 0, 0]);
    ctx.fill();

    // ... (rest of the loop stays the same)
 
        // Value label on top of bar (only if bar is tall enough)
        if (height > 14) {
            ctx.fillStyle   = "#fff";
            ctx.font        = "bold 10px Inter, sans-serif";
            ctx.textAlign   = "center";
            ctx.fillText(v, x + barW / 2, yTop + 12);
        }
    });
 
    // ── X-axis labels ─────────────────────────────────────────────────────
    ctx.fillStyle  = "#4a5568";
    ctx.font       = "10px Inter, sans-serif";
    ctx.textAlign  = "center";
 
    labels.forEach((label, i) => {
        const x = barX(i) + barW / 2;
        const y = PAD_TOP + chartH + 14;
 
        // Rotate long labels so they don't overlap
        ctx.save();
        ctx.translate(x, y);
        ctx.rotate(-Math.PI / 4);
        ctx.fillText(label, 0, 0);
        ctx.restore();
    });
 
    // ── Delta reference line ──────────────────────────────────────────────
    const deltaY = toY(delta);
 
    ctx.save();
    ctx.setLineDash([6, 4]);
    ctx.strokeStyle = "#e53e3e";
    ctx.lineWidth   = 1.5;
    ctx.beginPath();
    ctx.moveTo(PAD_LEFT, deltaY);
    ctx.lineTo(PAD_LEFT + chartW, deltaY);
    ctx.stroke();
    ctx.restore();
 
    // Delta label
    ctx.fillStyle = "#e53e3e";
    ctx.font      = "bold 10px Inter, sans-serif";
    ctx.textAlign = "left";
    ctx.fillText("δ=" + delta, PAD_LEFT + chartW + 2, deltaY + 4);
 
    // ── Axis lines ────────────────────────────────────────────────────────
    ctx.strokeStyle = "#cbd5e0";
    ctx.lineWidth   = 1;
    ctx.setLineDash([]);
 
    // Y-axis
    ctx.beginPath();
    ctx.moveTo(PAD_LEFT, PAD_TOP);
    ctx.lineTo(PAD_LEFT, PAD_TOP + chartH);
    ctx.stroke();
 
    // X-axis
    ctx.beginPath();
    ctx.moveTo(PAD_LEFT, PAD_TOP + chartH);
    ctx.lineTo(PAD_LEFT + chartW, PAD_TOP + chartH);
    ctx.stroke();
 
    // ── Legend ────────────────────────────────────────────────────────────
    const legendX = PAD_LEFT + chartW - 150;
    const legendY = PAD_TOP + 8;
 
    // Within threshold swatch
    ctx.fillStyle = "#3182ce";
    ctx.fillRect(legendX, legendY, 10, 10);
    ctx.fillStyle = "#4a5568";
    ctx.font      = "10px Inter, sans-serif";
    ctx.textAlign = "left";
    ctx.fillText("Within δ", legendX + 14, legendY + 9);
 
    // Above threshold swatch
    ctx.fillStyle = "#e53e3e";
    ctx.fillRect(legendX + 75, legendY, 10, 10);
    ctx.fillStyle = "#4a5568";
    ctx.fillText("Above δ", legendX + 89, legendY + 9);
}