(function (global) {
    'use strict';

    function valueArray(dataset) {
        return dataset && Array.isArray(dataset.data) ? dataset.data.map(function (v) {
            var n = Number(v);
            return isFinite(n) ? n : 0;
        }) : [];
    }

    function getCanvas(target) {
        if (!target) { return null; }
        if (target.canvas) { return target.canvas; }
        return target;
    }

    function fit(canvas) {
        var rect = canvas.getBoundingClientRect();
        var ratio = global.devicePixelRatio || 1;
        var width = Math.max(180, Math.floor(rect.width || canvas.clientWidth || 400));
        var height = Math.max(140, Math.floor(rect.height || canvas.clientHeight || 240));
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        var ctx = canvas.getContext('2d');
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        return {ctx: ctx, width: width, height: height};
    }

    function text(ctx, value, x, y, align) {
        ctx.fillStyle = '#64748b';
        ctx.font = '11px sans-serif';
        ctx.textAlign = align || 'center';
        ctx.fillText(String(value), x, y);
    }

    function lineChart(canvas, config) {
        var box = fit(canvas), ctx = box.ctx, w = box.width, h = box.height;
        var labels = (config.data && config.data.labels) || [];
        var ds = config.data && config.data.datasets ? config.data.datasets[0] : {};
        var values = valueArray(ds);
        var max = Math.max.apply(null, values.concat([1]));
        var left = 42, right = 12, top = 18, bottom = 30;
        var cw = w - left - right, ch = h - top - bottom;

        ctx.clearRect(0, 0, w, h);
        ctx.strokeStyle = '#e2e8f0';
        ctx.lineWidth = 1;
        for (var g = 0; g <= 4; g++) {
            var gy = top + (ch * g / 4);
            ctx.beginPath(); ctx.moveTo(left, gy); ctx.lineTo(w - right, gy); ctx.stroke();
        }

        if (!values.length) { text(ctx, 'No data', w / 2, h / 2); return; }
        var step = values.length > 1 ? cw / (values.length - 1) : cw;
        ctx.strokeStyle = ds.borderColor || '#16a34a';
        ctx.lineWidth = Number(ds.borderWidth || 2.5);
        ctx.beginPath();
        values.forEach(function (v, i) {
            var x = left + (values.length > 1 ? i * step : cw / 2);
            var y = top + ch - (v / max) * ch;
            if (i === 0) { ctx.moveTo(x, y); } else { ctx.lineTo(x, y); }
        });
        ctx.stroke();

        ctx.fillStyle = ds.borderColor || '#16a34a';
        values.forEach(function (v, i) {
            var x = left + (values.length > 1 ? i * step : cw / 2);
            var y = top + ch - (v / max) * ch;
            ctx.beginPath(); ctx.arc(x, y, 3, 0, Math.PI * 2); ctx.fill();
            if (labels[i] && (values.length <= 8 || i % Math.ceil(values.length / 7) === 0)) {
                text(ctx, labels[i], x, h - 9);
            }
        });
    }

    function barChart(canvas, config) {
        var box = fit(canvas), ctx = box.ctx, w = box.width, h = box.height;
        var labels = (config.data && config.data.labels) || [];
        var ds = config.data && config.data.datasets ? config.data.datasets[0] : {};
        var values = valueArray(ds);
        var max = Math.max.apply(null, values.concat([1]));
        var left = 34, right = 10, top = 16, bottom = 30;
        var cw = w - left - right, ch = h - top - bottom;
        var step = values.length ? cw / values.length : cw;
        var barWidth = Math.max(8, Math.min(34, step * 0.58));

        ctx.clearRect(0, 0, w, h);
        ctx.strokeStyle = '#e2e8f0';
        for (var g = 0; g <= 4; g++) {
            var gy = top + (ch * g / 4);
            ctx.beginPath(); ctx.moveTo(left, gy); ctx.lineTo(w - right, gy); ctx.stroke();
        }

        values.forEach(function (v, i) {
            var bh = (v / max) * ch;
            var x = left + i * step + (step - barWidth) / 2;
            var y = top + ch - bh;
            ctx.fillStyle = Array.isArray(ds.backgroundColor) ? ds.backgroundColor[i % ds.backgroundColor.length] : (ds.backgroundColor || '#86c97a');
            ctx.fillRect(x, y, barWidth, bh);
            if (labels[i]) { text(ctx, labels[i], x + barWidth / 2, h - 9); }
        });
    }

    function doughnutChart(canvas, config) {
        var box = fit(canvas), ctx = box.ctx, w = box.width, h = box.height;
        var ds = config.data && config.data.datasets ? config.data.datasets[0] : {};
        var values = valueArray(ds);
        var total = values.reduce(function (a, b) { return a + b; }, 0);
        var colors = Array.isArray(ds.backgroundColor) ? ds.backgroundColor : ['#16a34a', '#84cc16', '#f59e0b', '#38bdf8', '#94a3b8'];
        var radius = Math.max(30, Math.min(w, h) * 0.42);
        var inner = radius * 0.68;
        var cx = w / 2, cy = h / 2;
        var angle = -Math.PI / 2;
        ctx.clearRect(0, 0, w, h);

        if (total <= 0) {
            ctx.strokeStyle = '#e2e8f0'; ctx.lineWidth = radius - inner;
            ctx.beginPath(); ctx.arc(cx, cy, (radius + inner) / 2, 0, Math.PI * 2); ctx.stroke();
            return;
        }

        values.forEach(function (v, i) {
            var next = angle + (v / total) * Math.PI * 2;
            ctx.beginPath();
            ctx.arc(cx, cy, radius, angle, next);
            ctx.arc(cx, cy, inner, next, angle, true);
            ctx.closePath();
            ctx.fillStyle = colors[i % colors.length];
            ctx.fill();
            angle = next;
        });
    }

    function Chart(target, config) {
        this.canvas = getCanvas(target);
        this.config = config || {};
        if (!this.canvas) { return; }
        var type = this.config.type || 'line';
        if (type === 'bar') { barChart(this.canvas, this.config); }
        else if (type === 'doughnut') { doughnutChart(this.canvas, this.config); }
        else { lineChart(this.canvas, this.config); }
    }

    Chart.defaults = {font: {}, color: '#64748b'};
    global.Chart = Chart;
}(window));
