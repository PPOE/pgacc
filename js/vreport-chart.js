/*
 * Running-total chart of the virtual-account report (vreport.php, v_graph()).
 * Reads the points [timestamp in ms, running total] from the JSON element
 * #graph-data and draws them with Chart.js (js/chart.umd.min.js) into
 * #graph-canvas. No inline script, so the page works with a Content Security
 * Policy without 'unsafe-inline'.
 *
 * Keeps the look of the former jQuery Flot 0.8.2 chart: a stepped line with
 * points (also at the step corners) in Flot's first series color, Flot's axis ranges and ticks (ported
 * below: time ticks in UTC with the labels %d.%m.%Y, y from 1.1 times the
 * lowest balance, at most 0, with a 2% margin above), a grid with a 2px
 * border, no legend, no animation, and the hover text "d.m.Y: 170.00€" in a
 * translucent white box 20px below and 60px left of the point.
 */
(function () {
    'use strict';

    var COLOR = '#edc240';      // Flot's first series color
    var GRID = '#545454';       // Flot's grid.color
    var TICK = 'rgba(84, 84, 84, 0.22)';
    var HIGHLIGHT = 'rgba(237, 194, 64, 0.5)';

    var timeUnitSize = {
        second: 1000,
        minute: 60 * 1000,
        hour: 60 * 60 * 1000,
        day: 24 * 60 * 60 * 1000,
        month: 30 * 24 * 60 * 60 * 1000,
        year: 365.2425 * 24 * 60 * 60 * 1000
    };
    var timeSpec = [
        [1, 'second'], [2, 'second'], [5, 'second'], [10, 'second'], [30, 'second'],
        [1, 'minute'], [2, 'minute'], [5, 'minute'], [10, 'minute'], [30, 'minute'],
        [1, 'hour'], [2, 'hour'], [4, 'hour'], [8, 'hour'], [12, 'hour'],
        [1, 'day'], [2, 'day'], [3, 'day'],
        [0.25, 'month'], [0.5, 'month'], [1, 'month'], [2, 'month'],
        [3, 'month'], [6, 'month'], [1, 'year']
    ];

    function floorInBase(n, base) {
        return base * Math.floor(n / base);
    }

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    // Flot's range: data range (or the fixed minimum), widened when empty,
    // plus the autoscale margin where the axis has one.
    function range(min, max, datamin, datamax, margin) {
        var delta = max - min;
        if (delta === 0) {
            max += max === 0 ? 1 : 0.01;
        } else if (margin) {
            max += delta * margin;
            if (max > 0 && datamax <= 0) {
                max = 0;
            }
        }
        return {min: min, max: max};
    }

    // Flot's tick generator of a time axis (mode "time", timezone UTC).
    function timeTicks(axis, noTicks) {
        var delta = (axis.max - axis.min) / noTicks;
        var i;
        for (i = 0; i < timeSpec.length - 1; ++i) {
            if (delta < (timeSpec[i][0] * timeUnitSize[timeSpec[i][1]]
                + timeSpec[i + 1][0] * timeUnitSize[timeSpec[i + 1][1]]) / 2) {
                break;
            }
        }
        var size = timeSpec[i][0];
        var unit = timeSpec[i][1];
        if (unit === 'year') {
            var magn = Math.pow(10, Math.floor(Math.log(delta / timeUnitSize.year) / Math.LN10));
            var norm = (delta / timeUnitSize.year) / magn;
            size = (norm < 1.5 ? 1 : norm < 3 ? 2 : norm < 7.5 ? 5 : 10) * magn;
            if (size < 1) {
                size = 1;
            }
        }
        var step = size * timeUnitSize[unit];
        var d = new Date(axis.min);
        if (unit === 'second') {
            d.setUTCSeconds(floorInBase(d.getUTCSeconds(), size));
        } else if (unit === 'minute') {
            d.setUTCMinutes(floorInBase(d.getUTCMinutes(), size));
        } else if (unit === 'hour') {
            d.setUTCHours(floorInBase(d.getUTCHours(), size));
        } else if (unit === 'month') {
            d.setUTCMonth(floorInBase(d.getUTCMonth(), size));
        } else if (unit === 'year') {
            d.setUTCFullYear(floorInBase(d.getUTCFullYear(), size));
        }
        d.setUTCMilliseconds(0);
        if (step >= timeUnitSize.minute) {
            d.setUTCSeconds(0);
        }
        if (step >= timeUnitSize.hour) {
            d.setUTCMinutes(0);
        }
        if (step >= timeUnitSize.day) {
            d.setUTCHours(0);
        }
        if (step >= timeUnitSize.day * 4) {
            d.setUTCDate(1);
        }
        if (step >= timeUnitSize.month * 2) {
            d.setUTCMonth(floorInBase(d.getUTCMonth(), 3));
        }
        if (step >= timeUnitSize.month * 6) {
            d.setUTCMonth(floorInBase(d.getUTCMonth(), 6));
        }
        if (step >= timeUnitSize.year) {
            d.setUTCMonth(0);
        }

        var ticks = [];
        var carry = 0;
        var v = NaN;
        var prev;
        do {
            prev = v;
            v = d.getTime();
            ticks.push(v);
            if (unit === 'month') {
                if (size < 1) {
                    // Fractions of a month, without ending in the middle of a day.
                    d.setUTCDate(1);
                    var start = d.getTime();
                    d.setUTCMonth(d.getUTCMonth() + 1);
                    var end = d.getTime();
                    d.setTime(v + carry * timeUnitSize.hour + (end - start) * size);
                    carry = d.getUTCHours();
                    d.setUTCHours(0);
                } else {
                    d.setUTCMonth(d.getUTCMonth() + size);
                }
            } else if (unit === 'year') {
                d.setUTCFullYear(d.getUTCFullYear() + size);
            } else {
                d.setTime(v + step);
            }
        } while (v < axis.max && v !== prev);
        return ticks;
    }

    // Flot's tick generator of a number axis; returns the ticks and their
    // number of decimals.
    function numberTicks(axis, noTicks) {
        var delta = (axis.max - axis.min) / noTicks;
        var dec = -Math.floor(Math.log(delta) / Math.LN10);
        var magn = Math.pow(10, -dec);
        var norm = delta / magn;
        var size;
        if (norm < 1.5) {
            size = 1;
        } else if (norm < 3) {
            size = 2;
            if (norm > 2.25) {
                size = 2.5;
                ++dec;
            }
        } else if (norm < 7.5) {
            size = 5;
        } else {
            size = 10;
        }
        size *= magn;
        var start = floorInBase(axis.min, size);
        var ticks = [];
        var i = 0;
        var v = NaN;
        var prev;
        do {
            prev = v;
            v = start + i * size;
            ticks.push(v);
            ++i;
        } while (v < axis.max && v !== prev);
        return {ticks: ticks, decimals: Math.max(0, dec)};
    }

    function numberLabel(v, decimals) {
        var factor = decimals ? Math.pow(10, decimals) : 1;
        var formatted = '' + Math.round(v * factor) / factor;
        var decimal = formatted.indexOf('.');
        var precision = decimal === -1 ? 0 : formatted.length - decimal - 1;
        if (precision < decimals) {
            return (precision ? formatted : formatted + '.') + ('' + factor).substr(1, decimals - precision);
        }
        return formatted;
    }

    function dateLabel(v) {
        var d = new Date(v);
        return pad(d.getUTCDate()) + '.' + pad(d.getUTCMonth() + 1) + '.' + d.getUTCFullYear();
    }

    // The former hover text: browser-local date without leading zeros.
    function tooltipLabel(x, y) {
        var date = new Date(x);
        return date.getDate() + '.' + (date.getMonth() + 1) + '.' + date.getFullYear() + ': ' + y.toFixed(2) + '€';
    }

    function draw(container, canvas, values) {
        // Like Flot's steps, a corner point (next date, previous total) is
        // inserted where the total changes; it gets a point and a hover text
        // like the data points.
        var points = [];
        values.forEach(function (value) {
            var point = {x: Number(value[0]), y: Number(value[1])};
            var prev = points[points.length - 1];
            if (prev && prev.x !== point.x && prev.y !== point.y) {
                points.push({x: point.x, y: prev.y});
            }
            points.push(point);
        });
        var xs = points.map(function (p) { return p.x; });
        var ys = points.map(function (p) { return p.y; });
        var xmin = xs.length ? Math.min.apply(null, xs) : 0;
        var xmax = xs.length ? Math.max.apply(null, xs) : 0;
        var ymax = ys.length ? Math.max.apply(null, ys) : 0;
        var ymin = Math.min.apply(null, [0].concat(ys)) * 1.1;
        var x = range(xmin, xmax, xmin, xmax, null);
        var y = range(ymin, Math.max(ymin, ymax), ymin, ymax, 0.02);

        var style = window.getComputedStyle(container);
        var fontSize = parseFloat(style.fontSize) || 12;
        var tickFont = {family: style.fontFamily, size: Math.round(fontSize / 1.2)};
        var bodyFont = {family: style.fontFamily, size: fontSize};
        var yDecimals = 0;

        Chart.Tooltip.positioners.flot = function (items) {
            if (!items.length) {
                return false;
            }
            return {x: items[0].element.x - 60, y: items[0].element.y + 20, xAlign: 'left', yAlign: 'top'};
        };

        var border = {
            id: 'flotBorder',
            beforeDatasetsDraw: function (chart) {
                // Flot's shadow below lines and points.
                chart.ctx.save();
                chart.ctx.shadowColor = 'rgba(0, 0, 0, 0.15)';
                chart.ctx.shadowOffsetY = 2;
                chart.ctx.shadowBlur = 2;
            },
            afterDatasetsDraw: function (chart) {
                chart.ctx.restore();
                var area = chart.chartArea;
                chart.ctx.save();
                // Flot's highlight of the hovered point.
                chart.getActiveElements().forEach(function (active) {
                    chart.ctx.beginPath();
                    chart.ctx.lineWidth = 4;
                    chart.ctx.strokeStyle = HIGHLIGHT;
                    chart.ctx.arc(active.element.x, active.element.y, 6, 0, 2 * Math.PI);
                    chart.ctx.stroke();
                });
                chart.ctx.lineWidth = 2;
                chart.ctx.strokeStyle = GRID;
                chart.ctx.strokeRect(area.left - 1, area.top - 1, area.right - area.left + 2, area.bottom - area.top + 2);
                chart.ctx.restore();
            }
        };

        function scale(axis, buildTicks, label) {
            return {
                type: 'linear',
                min: axis.min,
                max: axis.max,
                border: {display: false},
                grid: {color: TICK, drawTicks: false},
                ticks: {
                    autoSkip: false,
                    maxRotation: 0,
                    padding: 5,
                    color: GRID,
                    font: tickFont,
                    callback: label
                },
                afterBuildTicks: buildTicks
            };
        }

        new Chart(canvas, {
            type: 'line',
            data: {
                datasets: [{
                    data: points,
                    stepped: 'before',
                    borderColor: COLOR,
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBorderWidth: 2,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: COLOR,
                    pointHitRadius: 7,
                    pointHoverRadius: 3,
                    pointHoverBorderWidth: 2,
                    pointHoverBackgroundColor: '#ffffff',
                    pointHoverBorderColor: COLOR,
                    clip: false,
                    fill: false
                }]
            },
            options: {
                animation: false,
                responsive: true,
                maintainAspectRatio: false,
                parsing: false,
                layout: {padding: {top: 2, right: 2}},
                interaction: {mode: 'nearest', intersect: true},
                scales: {
                    x: scale(x, function (s) {
                        var ticks = timeTicks(x, 0.3 * Math.sqrt(s.chart.width));
                        s.ticks = ticks.filter(function (v) {
                            return v >= x.min && v <= x.max;
                        }).map(function (v) {
                            return {value: v};
                        });
                    }, dateLabel),
                    y: scale(y, function (s) {
                        var result = numberTicks(y, 0.3 * Math.sqrt(s.chart.height));
                        yDecimals = result.decimals;
                        // Flot extends the axis to the last tick.
                        var last = result.ticks[result.ticks.length - 1];
                        if (result.ticks.length > 1 && last > y.max) {
                            s.max = last;
                        }
                        s.ticks = result.ticks.filter(function (v) {
                            return v >= s.min && v <= s.max;
                        }).map(function (v) {
                            return {value: v};
                        });
                    }, function (v) {
                        return numberLabel(v, yDecimals);
                    })
                },
                plugins: {
                    legend: {display: false},
                    tooltip: {
                        position: 'flot',
                        backgroundColor: 'rgba(255, 255, 255, 0.8)',
                        bodyColor: window.getComputedStyle(document.body).color,
                        bodyFont: bodyFont,
                        padding: 8,
                        cornerRadius: 0,
                        caretSize: 0,
                        caretPadding: 0,
                        displayColors: false,
                        callbacks: {
                            title: function () {
                                return '';
                            },
                            label: function (item) {
                                return tooltipLabel(item.parsed.x, item.parsed.y);
                            }
                        }
                    }
                }
            },
            plugins: [border]
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var data = document.getElementById('graph-data');
        var container = document.getElementById('graph');
        var canvas = document.getElementById('graph-canvas');
        if (!data || !container || !canvas || typeof Chart === 'undefined') {
            return;
        }
        draw(container, canvas, JSON.parse(data.textContent));
    });
}());
