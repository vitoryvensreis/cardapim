/*!
 * protomaps-leaflet (BSD-3-Clause) e dependencias, juntados por dev/vendor/protomaps-leaflet/build.mjs.
 * Licencas em public/assets/vendor/protomaps-leaflet/LICENSES.txt. Versoes (repositorio, ref, pasta):
 *   protomaps-leaflet protomaps/protomaps-leaflet 5a153e7d5f49ec903e60eba9700a8ededd5a750e
 *   PMTiles protomaps/PMTiles d7357088c5b971b6d756a43b798182bb6777043d
 *   basemaps protomaps/basemaps 7caa0f50ab2b7215f7e497dd41ab9b4a5067e955
 *   fflate 101arrowz/fflate v0.8.2
 *   point-geometry mapbox/point-geometry v1.1.0
 *   vector-tile mapbox/vector-tile-js v2.0.3
 *   pbf mapbox/pbf v4.0.1
 *   color2k ricokahler/color2k v2.0.3
 *   potpack mapbox/potpack v1.0.2
 *   rbush mourner/rbush v3.0.1
 *   quickselect mourner/quickselect v2.0.0
 */
var protomapsL = (function () {
  "use strict";
  var defs = {
/* protomaps-leaflet/src/index.ts */
0: function (module, exports, __req) {
"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __exportStar = (this && this.__exportStar) || function(m, exports) {
    for (var p in m) if (p !== "default" && !Object.prototype.hasOwnProperty.call(exports, p)) __createBinding(exports, m, p);
};
Object.defineProperty(exports, "__esModule", { value: true });
__exportStar(__req(1), exports);
__exportStar(__req(2), exports);
__exportStar(__req(3), exports);
__exportStar(__req(4), exports);
__exportStar(__req(5), exports);
__exportStar(__req(6), exports);
__exportStar(__req(7), exports);
__exportStar(__req(8), exports);
__exportStar(__req(9), exports);

},
/* protomaps-leaflet/src/frontends/static.ts */
1: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.Static = exports.getZoom = void 0;
const point_geometry_1 = __importDefault(__req(10));
const basemaps_1 = __req(11);
const style_1 = __req(5);
const labeler_1 = __req(9);
const painter_1 = __req(6);
const view_1 = __req(8);
const R = 6378137;
const MAX_LATITUDE = 85.0511287798;
const MAXCOORD = R * Math.PI;
const project = (latlng) => {
    const d = Math.PI / 180;
    const constrainedLat = Math.max(Math.min(MAX_LATITUDE, latlng.y), -MAX_LATITUDE);
    const sin = Math.sin(constrainedLat * d);
    return new point_geometry_1.default(R * latlng.x * d, (R * Math.log((1 + sin) / (1 - sin))) / 2);
};
const unproject = (point) => {
    const d = 180 / Math.PI;
    return {
        lat: (2 * Math.atan(Math.exp(point.y / R)) - Math.PI / 2) * d,
        lng: (point.x * d) / R,
    };
};
const instancedProject = (origin, displayZoom) => {
    return (latlng) => {
        const projected = project(latlng);
        const normalized = new point_geometry_1.default((projected.x + MAXCOORD) / (MAXCOORD * 2), 1 - (projected.y + MAXCOORD) / (MAXCOORD * 2));
        return normalized.mult(2 ** displayZoom * 256).sub(origin);
    };
};
const instancedUnproject = (origin, displayZoom) => {
    return (point) => {
        const normalized = new point_geometry_1.default(point.x, point.y)
            .add(origin)
            .div(2 ** displayZoom * 256);
        const projected = new point_geometry_1.default(normalized.x * (MAXCOORD * 2) - MAXCOORD, (1 - normalized.y) * (MAXCOORD * 2) - MAXCOORD);
        return unproject(projected);
    };
};
const getZoom = (degreesLng, cssPixels) => {
    const d = cssPixels * (360 / degreesLng);
    return Math.log2(d / 256);
};
exports.getZoom = getZoom;
class Static {
    constructor(options) {
        if (options.flavor) {
            const flavor = (0, basemaps_1.namedFlavor)(options.flavor);
            this.paintRules = (0, style_1.paintRules)(flavor);
            this.labelRules = (0, style_1.labelRules)(flavor, options.lang || "en");
            this.backgroundColor = flavor.background;
        }
        else {
            this.paintRules = options.paintRules || [];
            this.labelRules = options.labelRules || [];
            this.backgroundColor = options.backgroundColor;
        }
        this.views = (0, view_1.sourcesToViews)(options);
        this.debug = options.debug || "";
    }
    async drawContext(ctx, width, height, latlng, displayZoom) {
        const center = project(latlng);
        const normalizedCenter = new point_geometry_1.default((center.x + MAXCOORD) / (MAXCOORD * 2), 1 - (center.y + MAXCOORD) / (MAXCOORD * 2));
        const origin = normalizedCenter
            .clone()
            .mult(2 ** displayZoom * 256)
            .sub(new point_geometry_1.default(width / 2, height / 2));
        const bbox = {
            minX: origin.x,
            minY: origin.y,
            maxX: origin.x + width,
            maxY: origin.y + height,
        };
        const promises = [];
        for (const [k, v] of this.views) {
            const promise = v.getBbox(displayZoom, bbox);
            promises.push({ key: k, promise: promise });
        }
        const tileResponses = await Promise.all(promises.map((o) => {
            return o.promise.then((v) => {
                return { status: "fulfilled", value: v, key: o.key };
            }, (error) => {
                return { status: "rejected", value: [], reason: error, key: o.key };
            });
        }));
        const preparedTilemap = new Map();
        for (const tileResponse of tileResponses) {
            if (tileResponse.status === "fulfilled") {
                preparedTilemap.set(tileResponse.key, tileResponse.value);
            }
        }
        const start = performance.now();
        const labeler = new labeler_1.Labeler(displayZoom, ctx, this.labelRules, 16, undefined);
        const layoutTime = labeler.add(preparedTilemap);
        if (this.backgroundColor) {
            ctx.save();
            ctx.fillStyle = this.backgroundColor;
            ctx.fillRect(0, 0, width, height);
            ctx.restore();
        }
        const paintRules = this.paintRules;
        const p = (0, painter_1.paint)(ctx, displayZoom, preparedTilemap, labeler.index, paintRules, bbox, origin, true, this.debug);
        if (this.debug) {
            ctx.save();
            ctx.translate(-origin.x, -origin.y);
            ctx.strokeStyle = this.debug;
            ctx.fillStyle = this.debug;
            ctx.font = "12px sans-serif";
            let idx = 0;
            for (const [k, v] of preparedTilemap) {
                for (const preparedTile of v) {
                    ctx.strokeRect(preparedTile.origin.x, preparedTile.origin.y, preparedTile.dim, preparedTile.dim);
                    const dt = preparedTile.dataTile;
                    ctx.fillText(`${k + (k ? " " : "") + dt.z} ${dt.x} ${dt.y}`, preparedTile.origin.x + 4, preparedTile.origin.y + 14 * (1 + idx));
                }
                idx++;
            }
            ctx.restore();
        }
        return {
            elapsed: performance.now() - start,
            project: instancedProject(origin, displayZoom),
            unproject: instancedUnproject(origin, displayZoom),
        };
    }
    async drawCanvas(canvas, latlng, displayZoom, options = {}) {
        const dpr = window.devicePixelRatio;
        const width = canvas.clientWidth;
        const height = canvas.clientHeight;
        if (!(canvas.width === width * dpr && canvas.height === height * dpr)) {
            canvas.width = width * dpr;
            canvas.height = height * dpr;
        }
        if (options.lang)
            canvas.lang = options.lang;
        const ctx = canvas.getContext("2d");
        if (!ctx) {
            console.error("Failed to initialize canvas2d context.");
            return;
        }
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        return this.drawContext(ctx, width, height, latlng, displayZoom);
    }
    async drawContextBounds(ctx, topLeft, bottomRight, width, height) {
        const deltaDegrees = bottomRight.x - topLeft.x;
        const center = new point_geometry_1.default((topLeft.x + bottomRight.x) / 2, (topLeft.y + bottomRight.y) / 2);
        return this.drawContext(ctx, width, height, center, (0, exports.getZoom)(deltaDegrees, width));
    }
    async drawCanvasBounds(canvas, topLeft, bottomRight, width, options = {}) {
        const deltaDegrees = bottomRight.x - topLeft.x;
        const center = new point_geometry_1.default((topLeft.x + bottomRight.x) / 2, (topLeft.y + bottomRight.y) / 2);
        return this.drawCanvas(canvas, center, (0, exports.getZoom)(deltaDegrees, width), options);
    }
}
exports.Static = Static;

},
/* protomaps-leaflet/src/frontends/leaflet.ts */
2: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.leafletLayer = void 0;
const point_geometry_1 = __importDefault(__req(10));
const basemaps_1 = __req(11);
const style_1 = __req(5);
const labeler_1 = __req(9);
const painter_1 = __req(6);
const view_1 = __req(8);
const timer = (duration) => {
    return new Promise((resolve) => {
        setTimeout(() => {
            resolve();
        }, duration);
    });
};
const reflect = (promise) => {
    return promise.then((v) => {
        return { status: "fulfilled", value: v };
    }, (error) => {
        return { status: "rejected", reason: error };
    });
};
const leafletLayer = (options = {}) => {
    class LeafletLayer extends L.GridLayer {
        constructor(options = {}) {
            var _a;
            if (options.noWrap && !options.bounds)
                options.bounds = [
                    [-90, -180],
                    [90, 180],
                ];
            if (options.attribution == null)
                options.attribution =
                    '<a href="https://protomaps.com">Protomaps</a> © <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>';
            super(options);
            if (options.flavor) {
                const flavor = (0, basemaps_1.namedFlavor)(options.flavor);
                this.paintRules = (0, style_1.paintRules)(flavor);
                this.labelRules = (0, style_1.labelRules)(flavor, options.lang || "en");
                this.backgroundColor = flavor.background;
            }
            else {
                this.paintRules = options.paintRules || [];
                this.labelRules = options.labelRules || [];
                this.backgroundColor = options.backgroundColor;
            }
            this.devicePixelRatio =
                (_a = options.devicePixelRatio) !== null && _a !== void 0 ? _a : window.devicePixelRatio;
            this.lastRequestedZ = undefined;
            this.tasks = options.tasks || [];
            this.views = (0, view_1.sourcesToViews)(options);
            this.debug = options.debug;
            const scratch = document.createElement("canvas").getContext("2d");
            this.scratch = scratch;
            this.onTilesInvalidated = (tiles) => {
                for (const t of tiles) {
                    this.rerenderTile(t);
                }
            };
            this.labelers = new labeler_1.Labelers(this.scratch, this.labelRules, 16, this.onTilesInvalidated);
            this.tileSize = 256 * this.devicePixelRatio;
            this.tileDelay = options.tileDelay || 3;
            this.lang = options.lang;
        }
        async renderTile(coords, element, key, done = () => { }) {
            this.lastRequestedZ = coords.z;
            const promises = [];
            for (const [k, v] of this.views) {
                const promise = v.getDisplayTile(coords);
                promises.push({ key: k, promise: promise });
            }
            const tileResponses = await Promise.all(promises.map((o) => {
                return o.promise.then((v) => {
                    return { status: "fulfilled", value: v, key: o.key };
                }, (error) => {
                    return { status: "rejected", reason: error, key: o.key };
                });
            }));
            const preparedTilemap = new Map();
            for (const tileResponse of tileResponses) {
                if (tileResponse.status === "fulfilled") {
                    preparedTilemap.set(tileResponse.key, [tileResponse.value]);
                }
                else {
                    if (tileResponse.reason.name === "AbortError") {
                    }
                    else {
                        console.error(tileResponse.reason);
                    }
                }
            }
            if (element.key !== key)
                return;
            if (this.lastRequestedZ !== coords.z)
                return;
            await Promise.all(this.tasks.map(reflect));
            if (element.key !== key)
                return;
            if (this.lastRequestedZ !== coords.z)
                return;
            const layoutTime = this.labelers.add(coords.z, preparedTilemap);
            if (element.key !== key)
                return;
            if (this.lastRequestedZ !== coords.z)
                return;
            const labelData = this.labelers.getIndex(coords.z);
            if (!this._map)
                return;
            const center = this._map.getCenter().wrap();
            const pixelBounds = this._getTiledPixelBounds(center);
            const tileRange = this._pxBoundsToTileRange(pixelBounds);
            const tileCenter = tileRange.getCenter();
            const priority = coords.distanceTo(tileCenter) * this.tileDelay;
            await timer(priority);
            if (element.key !== key)
                return;
            if (this.lastRequestedZ !== coords.z)
                return;
            const buf = 16;
            const bbox = {
                minX: 256 * coords.x - buf,
                minY: 256 * coords.y - buf,
                maxX: 256 * (coords.x + 1) + buf,
                maxY: 256 * (coords.y + 1) + buf,
            };
            const origin = new point_geometry_1.default(256 * coords.x, 256 * coords.y);
            element.width = this.tileSize;
            element.height = this.tileSize;
            const ctx = element.getContext("2d");
            if (!ctx) {
                console.error("Failed to get Canvas context");
                return;
            }
            ctx.setTransform(this.tileSize / 256, 0, 0, this.tileSize / 256, 0, 0);
            ctx.clearRect(0, 0, 256, 256);
            if (this.backgroundColor) {
                ctx.save();
                ctx.fillStyle = this.backgroundColor;
                ctx.fillRect(0, 0, 256, 256);
                ctx.restore();
            }
            let paintingTime = 0;
            const paintRules = this.paintRules;
            paintingTime = (0, painter_1.paint)(ctx, coords.z, preparedTilemap, this.xray ? null : labelData, paintRules, bbox, origin, false, this.debug);
            if (this.debug) {
                ctx.save();
                ctx.fillStyle = this.debug;
                ctx.font = "600 12px sans-serif";
                ctx.fillText(`${coords.z} ${coords.x} ${coords.y}`, 4, 14);
                ctx.font = "12px sans-serif";
                let ypos = 28;
                for (const [k, v] of preparedTilemap) {
                    const dt = v[0].dataTile;
                    ctx.fillText(`${k + (k ? " " : "") + dt.z} ${dt.x} ${dt.y}`, 4, ypos);
                    ypos += 14;
                }
                ctx.font = "600 10px sans-serif";
                if (paintingTime > 8) {
                    ctx.fillText(`${paintingTime.toFixed()} ms paint`, 4, ypos);
                    ypos += 14;
                }
                if (layoutTime > 8) {
                    ctx.fillText(`${layoutTime.toFixed()} ms layout`, 4, ypos);
                }
                ctx.strokeStyle = this.debug;
                ctx.lineWidth = 0.5;
                ctx.beginPath();
                ctx.moveTo(0, 0);
                ctx.lineTo(0, 256);
                ctx.stroke();
                ctx.lineWidth = 0.5;
                ctx.beginPath();
                ctx.moveTo(0, 0);
                ctx.lineTo(256, 0);
                ctx.stroke();
                ctx.restore();
            }
            done();
        }
        rerenderTile(key) {
            for (const unwrappedK in this._tiles) {
                const wrappedCoord = this._wrapCoords(this._keyToTileCoords(unwrappedK));
                if (key === this._tileCoordsToKey(wrappedCoord)) {
                    this.renderTile(wrappedCoord, this._tiles[unwrappedK].el, key);
                }
            }
        }
        queryTileFeaturesDebug(lng, lat, brushSize = 16) {
            const featuresBySourceName = new Map();
            for (const [sourceName, view] of this.views) {
                featuresBySourceName.set(sourceName, view.queryFeatures(lng, lat, this._map.getZoom(), brushSize));
            }
            return featuresBySourceName;
        }
        clearLayout() {
            this.labelers = new labeler_1.Labelers(this.scratch, this.labelRules, 16, this.onTilesInvalidated);
        }
        rerenderTiles() {
            for (const unwrappedK in this._tiles) {
                const wrappedCoord = this._wrapCoords(this._keyToTileCoords(unwrappedK));
                const key = this._tileCoordsToKey(wrappedCoord);
                this.renderTile(wrappedCoord, this._tiles[unwrappedK].el, key);
            }
        }
        createTile(coords, showTile) {
            const element = L.DomUtil.create("canvas", "leaflet-tile");
            element.lang = this.lang;
            const key = this._tileCoordsToKey(coords);
            element.key = key;
            this.renderTile(coords, element, key, () => {
                showTile(undefined, element);
            });
            return element;
        }
        _removeTile(key) {
            const tile = this._tiles[key];
            if (!tile) {
                return;
            }
            tile.el.removed = true;
            tile.el.key = undefined;
            L.DomUtil.removeClass(tile.el, "leaflet-tile-loaded");
            tile.el.width = tile.el.height = 0;
            L.DomUtil.remove(tile.el);
            delete this._tiles[key];
            this.fire("tileunload", {
                tile: tile.el,
                coords: this._keyToTileCoords(key),
            });
        }
    }
    return new LeafletLayer(options);
};
exports.leafletLayer = leafletLayer;

},
/* protomaps-leaflet/src/symbolizer.ts */
3: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.LineLabelSymbolizer = exports.LineLabelPlacement = exports.OffsetTextSymbolizer = exports.OffsetSymbolizer = exports.CenteredTextSymbolizer = exports.TextSymbolizer = exports.Padding = exports.CenteredSymbolizer = exports.GroupSymbolizer = exports.FlexSymbolizer = exports.ShieldSymbolizer = exports.CircleSymbolizer = exports.IconSymbolizer = exports.LineSymbolizer = exports.PolygonSymbolizer = exports.createPattern = exports.TextPlacements = exports.Justify = void 0;
exports.arr = arr;
exports.exp = exp;
exports.step = step;
exports.linear = linear;
const point_geometry_1 = __importDefault(__req(10));
const attribute_1 = __req(12);
const line_1 = __req(13);
const text_1 = __req(14);
const tilecache_1 = __req(7);
var Justify;
(function (Justify) {
    Justify[Justify["Left"] = 1] = "Left";
    Justify[Justify["Center"] = 2] = "Center";
    Justify[Justify["Right"] = 3] = "Right";
})(Justify || (exports.Justify = Justify = {}));
var TextPlacements;
(function (TextPlacements) {
    TextPlacements[TextPlacements["N"] = 1] = "N";
    TextPlacements[TextPlacements["Ne"] = 2] = "Ne";
    TextPlacements[TextPlacements["E"] = 3] = "E";
    TextPlacements[TextPlacements["Se"] = 4] = "Se";
    TextPlacements[TextPlacements["S"] = 5] = "S";
    TextPlacements[TextPlacements["Sw"] = 6] = "Sw";
    TextPlacements[TextPlacements["W"] = 7] = "W";
    TextPlacements[TextPlacements["Nw"] = 8] = "Nw";
})(TextPlacements || (exports.TextPlacements = TextPlacements = {}));
const createPattern = (width, height, fn) => {
    const canvas = document.createElement("canvas");
    const ctx = canvas.getContext("2d");
    canvas.width = width;
    canvas.height = height;
    if (ctx !== null)
        fn(canvas, ctx);
    return canvas;
};
exports.createPattern = createPattern;
class PolygonSymbolizer {
    constructor(options) {
        var _a;
        this.pattern = options.pattern;
        this.fill = new attribute_1.StringAttr(options.fill, "black");
        this.opacity = new attribute_1.NumberAttr(options.opacity, 1);
        this.stroke = new attribute_1.StringAttr(options.stroke, "black");
        this.width = new attribute_1.NumberAttr(options.width, 0);
        this.perFeature =
            (_a = (this.fill.perFeature ||
                this.opacity.perFeature ||
                this.stroke.perFeature ||
                this.width.perFeature ||
                options.perFeature)) !== null && _a !== void 0 ? _a : false;
        this.doStroke = false;
    }
    before(ctx, z) {
        if (!this.perFeature) {
            ctx.globalAlpha = this.opacity.get(z);
            ctx.fillStyle = this.fill.get(z);
            ctx.strokeStyle = this.stroke.get(z);
            const width = this.width.get(z);
            if (width > 0)
                this.doStroke = true;
            ctx.lineWidth = width;
        }
        if (this.pattern) {
            const patten = ctx.createPattern(this.pattern, "repeat");
            if (patten)
                ctx.fillStyle = patten;
        }
    }
    draw(ctx, geom, z, f) {
        let doStroke = false;
        if (this.perFeature) {
            ctx.globalAlpha = this.opacity.get(z, f);
            ctx.fillStyle = this.fill.get(z, f);
            const width = this.width.get(z, f);
            if (width) {
                doStroke = true;
                ctx.strokeStyle = this.stroke.get(z, f);
                ctx.lineWidth = width;
            }
        }
        const drawPath = () => {
            ctx.fill();
            if (doStroke || this.doStroke) {
                ctx.stroke();
            }
        };
        ctx.beginPath();
        for (const poly of geom) {
            for (let p = 0; p < poly.length; p++) {
                const pt = poly[p];
                if (p === 0)
                    ctx.moveTo(pt.x, pt.y);
                else
                    ctx.lineTo(pt.x, pt.y);
            }
        }
        drawPath();
    }
}
exports.PolygonSymbolizer = PolygonSymbolizer;
function arr(base, a) {
    return (z) => {
        const b = z - base;
        if (b >= 0 && b < a.length) {
            return a[b];
        }
        return 0;
    };
}
function getStopIndex(input, stops) {
    let idx = 0;
    while (stops[idx + 1][0] < input)
        idx++;
    return idx;
}
function interpolate(factor, start, end) {
    return factor * (end - start) + start;
}
function computeInterpolationFactor(z, idx, base, stops) {
    const difference = stops[idx + 1][0] - stops[idx][0];
    const progress = z - stops[idx][0];
    if (difference === 0)
        return 0;
    if (base === 1)
        return progress / difference;
    return (base ** progress - 1) / (base ** difference - 1);
}
function exp(base, stops) {
    return (z) => {
        if (stops.length < 1)
            return 0;
        if (z <= stops[0][0])
            return stops[0][1];
        if (z >= stops[stops.length - 1][0])
            return stops[stops.length - 1][1];
        const idx = getStopIndex(z, stops);
        const factor = computeInterpolationFactor(z, idx, base, stops);
        return interpolate(factor, stops[idx][1], stops[idx + 1][1]);
    };
}
function step(output0, stops) {
    return (z) => {
        if (stops.length < 1)
            return 0;
        let retval = output0;
        for (let i = 0; i < stops.length; i++) {
            if (z >= stops[i][0])
                retval = stops[i][1];
        }
        return retval;
    };
}
function linear(stops) {
    return exp(1, stops);
}
class LineSymbolizer {
    constructor(options) {
        var _a;
        this.color = new attribute_1.StringAttr(options.color, "black");
        this.width = new attribute_1.NumberAttr(options.width);
        this.opacity = new attribute_1.NumberAttr(options.opacity);
        this.dash = options.dash ? new attribute_1.ArrayAttr(options.dash) : null;
        this.dashColor = new attribute_1.StringAttr(options.dashColor, "black");
        this.dashWidth = new attribute_1.NumberAttr(options.dashWidth, 1.0);
        this.lineCap = new attribute_1.StringAttr(options.lineCap, "butt");
        this.lineJoin = new attribute_1.StringAttr(options.lineJoin, "miter");
        this.skip = false;
        this.perFeature = !!(((_a = this.dash) === null || _a === void 0 ? void 0 : _a.perFeature) ||
            this.color.perFeature ||
            this.opacity.perFeature ||
            this.width.perFeature ||
            this.lineCap.perFeature ||
            this.lineJoin.perFeature ||
            options.perFeature);
    }
    before(ctx, z) {
        if (!this.perFeature) {
            ctx.strokeStyle = this.color.get(z);
            ctx.lineWidth = this.width.get(z);
            ctx.globalAlpha = this.opacity.get(z);
            ctx.lineCap = this.lineCap.get(z);
            ctx.lineJoin = this.lineJoin.get(z);
        }
    }
    draw(ctx, geom, z, f) {
        if (this.skip)
            return;
        const strokePath = () => {
            if (this.perFeature) {
                ctx.globalAlpha = this.opacity.get(z, f);
                ctx.lineCap = this.lineCap.get(z, f);
                ctx.lineJoin = this.lineJoin.get(z, f);
            }
            if (this.dash) {
                ctx.save();
                if (this.perFeature) {
                    ctx.lineWidth = this.dashWidth.get(z, f);
                    ctx.strokeStyle = this.dashColor.get(z, f);
                    ctx.setLineDash(this.dash.get(z, f));
                }
                else {
                    ctx.setLineDash(this.dash.get(z));
                }
                ctx.stroke();
                ctx.restore();
            }
            else {
                ctx.save();
                if (this.perFeature) {
                    ctx.lineWidth = this.width.get(z, f);
                    ctx.strokeStyle = this.color.get(z, f);
                }
                ctx.stroke();
                ctx.restore();
            }
        };
        ctx.beginPath();
        for (const ls of geom) {
            for (let p = 0; p < ls.length; p++) {
                const pt = ls[p];
                if (p === 0)
                    ctx.moveTo(pt.x, pt.y);
                else
                    ctx.lineTo(pt.x, pt.y);
            }
        }
        strokePath();
    }
}
exports.LineSymbolizer = LineSymbolizer;
class IconSymbolizer {
    constructor(options) {
        this.name = options.name;
        this.sheet = options.sheet;
        this.dpr = window.devicePixelRatio;
    }
    place(layout, geom, feature) {
        const pt = geom[0];
        const a = new point_geometry_1.default(geom[0][0].x, geom[0][0].y);
        const loc = this.sheet.get(this.name);
        const width = loc.w / this.dpr;
        const height = loc.h / this.dpr;
        const bbox = {
            minX: a.x - width / 2,
            minY: a.y - height / 2,
            maxX: a.x + width / 2,
            maxY: a.y + height / 2,
        };
        const draw = (ctx) => {
            ctx.globalAlpha = 1;
            ctx.drawImage(this.sheet.canvas, loc.x, loc.y, loc.w, loc.h, -loc.w / 2 / this.dpr, -loc.h / 2 / this.dpr, loc.w / 2, loc.h / 2);
        };
        return [{ anchor: a, bboxes: [bbox], draw: draw }];
    }
}
exports.IconSymbolizer = IconSymbolizer;
class CircleSymbolizer {
    constructor(options) {
        this.radius = new attribute_1.NumberAttr(options.radius, 3);
        this.fill = new attribute_1.StringAttr(options.fill, "black");
        this.stroke = new attribute_1.StringAttr(options.stroke, "white");
        this.width = new attribute_1.NumberAttr(options.width, 0);
        this.opacity = new attribute_1.NumberAttr(options.opacity);
    }
    draw(ctx, geom, z, f) {
        ctx.globalAlpha = this.opacity.get(z, f);
        const radius = this.radius.get(z, f);
        const width = this.width.get(z, f);
        if (width > 0) {
            ctx.strokeStyle = this.stroke.get(z, f);
            ctx.lineWidth = width;
            ctx.beginPath();
            ctx.arc(geom[0][0].x, geom[0][0].y, radius + width / 2, 0, 2 * Math.PI);
            ctx.stroke();
        }
        ctx.fillStyle = this.fill.get(z, f);
        ctx.beginPath();
        ctx.arc(geom[0][0].x, geom[0][0].y, radius, 0, 2 * Math.PI);
        ctx.fill();
    }
    place(layout, geom, feature) {
        const pt = geom[0];
        const a = new point_geometry_1.default(geom[0][0].x, geom[0][0].y);
        const radius = this.radius.get(layout.zoom, feature);
        const bbox = {
            minX: a.x - radius,
            minY: a.y - radius,
            maxX: a.x + radius,
            maxY: a.y + radius,
        };
        const draw = (ctx) => {
            this.draw(ctx, [[new point_geometry_1.default(0, 0)]], layout.zoom, feature);
        };
        return [{ anchor: a, bboxes: [bbox], draw }];
    }
}
exports.CircleSymbolizer = CircleSymbolizer;
class ShieldSymbolizer {
    constructor(options) {
        this.font = new attribute_1.FontAttr(options);
        this.text = new attribute_1.TextAttr(options);
        this.fill = new attribute_1.StringAttr(options.fill, "black");
        this.background = new attribute_1.StringAttr(options.background, "white");
        this.padding = new attribute_1.NumberAttr(options.padding, 0);
    }
    place(layout, geom, f) {
        const property = this.text.get(layout.zoom, f);
        if (!property)
            return undefined;
        const font = this.font.get(layout.zoom, f);
        layout.scratch.font = font;
        const metrics = layout.scratch.measureText(property);
        const width = metrics.width;
        const ascent = metrics.actualBoundingBoxAscent;
        const descent = metrics.actualBoundingBoxDescent;
        const pt = geom[0];
        const a = new point_geometry_1.default(geom[0][0].x, geom[0][0].y);
        const p = this.padding.get(layout.zoom, f);
        const bbox = {
            minX: a.x - width / 2 - p,
            minY: a.y - ascent - p,
            maxX: a.x + width / 2 + p,
            maxY: a.y + descent + p,
        };
        const draw = (ctx) => {
            ctx.globalAlpha = 1;
            ctx.fillStyle = this.background.get(layout.zoom, f);
            ctx.fillRect(-width / 2 - p, -ascent - p, width + 2 * p, ascent + descent + 2 * p);
            ctx.fillStyle = this.fill.get(layout.zoom, f);
            ctx.font = font;
            ctx.fillText(property, -width / 2, 0);
        };
        return [{ anchor: a, bboxes: [bbox], draw: draw }];
    }
}
exports.ShieldSymbolizer = ShieldSymbolizer;
class FlexSymbolizer {
    constructor(list) {
        this.list = list;
    }
    place(layout, geom, feature) {
        let labels = this.list[0].place(layout, geom, feature);
        if (!labels)
            return undefined;
        let label = labels[0];
        const anchor = label.anchor;
        let bbox = label.bboxes[0];
        const height = bbox.maxY - bbox.minY;
        const draws = [{ draw: label.draw, translate: { x: 0, y: 0 } }];
        const newGeom = [[new point_geometry_1.default(geom[0][0].x, geom[0][0].y + height)]];
        for (let i = 1; i < this.list.length; i++) {
            labels = this.list[i].place(layout, newGeom, feature);
            if (labels) {
                label = labels[0];
                bbox = mergeBbox(bbox, label.bboxes[0]);
                draws.push({ draw: label.draw, translate: { x: 0, y: height } });
            }
        }
        const draw = (ctx) => {
            for (const sub of draws) {
                ctx.save();
                ctx.translate(sub.translate.x, sub.translate.y);
                sub.draw(ctx);
                ctx.restore();
            }
        };
        return [{ anchor: anchor, bboxes: [bbox], draw: draw }];
    }
}
exports.FlexSymbolizer = FlexSymbolizer;
const mergeBbox = (b1, b2) => {
    return {
        minX: Math.min(b1.minX, b2.minX),
        minY: Math.min(b1.minY, b2.minY),
        maxX: Math.max(b1.maxX, b2.maxX),
        maxY: Math.max(b1.maxY, b2.maxY),
    };
};
class GroupSymbolizer {
    constructor(list) {
        this.list = list;
    }
    place(layout, geom, feature) {
        const first = this.list[0];
        if (!first)
            return undefined;
        let labels = first.place(layout, geom, feature);
        if (!labels)
            return undefined;
        let label = labels[0];
        const anchor = label.anchor;
        let bbox = label.bboxes[0];
        const draws = [label.draw];
        for (let i = 1; i < this.list.length; i++) {
            labels = this.list[i].place(layout, geom, feature);
            if (!labels)
                return undefined;
            label = labels[0];
            bbox = mergeBbox(bbox, label.bboxes[0]);
            draws.push(label.draw);
        }
        const draw = (ctx) => {
            for (const d of draws) {
                d(ctx);
            }
        };
        return [{ anchor: anchor, bboxes: [bbox], draw: draw }];
    }
}
exports.GroupSymbolizer = GroupSymbolizer;
class CenteredSymbolizer {
    constructor(symbolizer) {
        this.symbolizer = symbolizer;
    }
    place(layout, geom, feature) {
        const a = geom[0][0];
        const placed = this.symbolizer.place(layout, [[new point_geometry_1.default(0, 0)]], feature);
        if (!placed || placed.length === 0)
            return undefined;
        const firstLabel = placed[0];
        const bbox = firstLabel.bboxes[0];
        const width = bbox.maxX - bbox.minX;
        const height = bbox.maxY - bbox.minY;
        const centered = {
            minX: a.x - width / 2,
            maxX: a.x + width / 2,
            minY: a.y - height / 2,
            maxY: a.y + height / 2,
        };
        const draw = (ctx) => {
            ctx.translate(-width / 2, height / 2 - bbox.maxY);
            firstLabel.draw(ctx, { justify: Justify.Center });
        };
        return [{ anchor: a, bboxes: [centered], draw: draw }];
    }
}
exports.CenteredSymbolizer = CenteredSymbolizer;
class Padding {
    constructor(padding, symbolizer) {
        this.padding = new attribute_1.NumberAttr(padding, 0);
        this.symbolizer = symbolizer;
    }
    place(layout, geom, feature) {
        const placed = this.symbolizer.place(layout, geom, feature);
        if (!placed || placed.length === 0)
            return undefined;
        const padding = this.padding.get(layout.zoom, feature);
        for (const label of placed) {
            for (const bbox of label.bboxes) {
                bbox.minX -= padding;
                bbox.minY -= padding;
                bbox.maxX += padding;
                bbox.maxY += padding;
            }
        }
        return placed;
    }
}
exports.Padding = Padding;
class TextSymbolizer {
    constructor(options) {
        this.font = new attribute_1.FontAttr(options);
        this.text = new attribute_1.TextAttr(options);
        this.fill = new attribute_1.StringAttr(options.fill, "black");
        this.stroke = new attribute_1.StringAttr(options.stroke, "black");
        this.width = new attribute_1.NumberAttr(options.width, 0);
        this.lineHeight = new attribute_1.NumberAttr(options.lineHeight, 1);
        this.letterSpacing = new attribute_1.NumberAttr(options.letterSpacing, 0);
        this.maxLineCodeUnits = new attribute_1.NumberAttr(options.maxLineChars, 15);
        this.justify = options.justify;
    }
    place(layout, geom, feature) {
        const property = this.text.get(layout.zoom, feature);
        if (!property)
            return undefined;
        const font = this.font.get(layout.zoom, feature);
        layout.scratch.font = font;
        const letterSpacing = this.letterSpacing.get(layout.zoom, feature);
        const lines = (0, text_1.linebreak)(property, this.maxLineCodeUnits.get(layout.zoom, feature));
        let longestLine = "";
        let longestLineLen = 0;
        for (const line of lines) {
            if (line.length > longestLineLen) {
                longestLineLen = line.length;
                longestLine = line;
            }
        }
        const metrics = layout.scratch.measureText(longestLine);
        const width = metrics.width + letterSpacing * (longestLineLen - 1);
        const ascent = metrics.actualBoundingBoxAscent;
        const descent = metrics.actualBoundingBoxDescent;
        const lineHeight = (ascent + descent) * this.lineHeight.get(layout.zoom, feature);
        const a = new point_geometry_1.default(geom[0][0].x, geom[0][0].y);
        const bbox = {
            minX: a.x,
            minY: a.y - ascent,
            maxX: a.x + width,
            maxY: a.y + descent + (lines.length - 1) * lineHeight,
        };
        const draw = (ctx, extra) => {
            ctx.globalAlpha = 1;
            ctx.font = font;
            ctx.fillStyle = this.fill.get(layout.zoom, feature);
            const textStrokeWidth = this.width.get(layout.zoom, feature);
            let y = 0;
            for (const line of lines) {
                let startX = 0;
                if (this.justify === Justify.Center ||
                    (extra && extra.justify === Justify.Center)) {
                    startX = (width - ctx.measureText(line).width) / 2;
                }
                else if (this.justify === Justify.Right ||
                    (extra && extra.justify === Justify.Right)) {
                    startX = width - ctx.measureText(line).width;
                }
                if (textStrokeWidth) {
                    ctx.lineWidth = textStrokeWidth * 2;
                    ctx.strokeStyle = this.stroke.get(layout.zoom, feature);
                    if (letterSpacing > 0) {
                        let xPos = startX;
                        for (const letter of line) {
                            ctx.strokeText(letter, xPos, y);
                            xPos += ctx.measureText(letter).width + letterSpacing;
                        }
                    }
                    else {
                        ctx.strokeText(line, startX, y);
                    }
                }
                if (letterSpacing > 0) {
                    let xPos = startX;
                    for (const letter of line) {
                        ctx.fillText(letter, xPos, y);
                        xPos += ctx.measureText(letter).width + letterSpacing;
                    }
                }
                else {
                    ctx.fillText(line, startX, y);
                }
                y += lineHeight;
            }
        };
        return [{ anchor: a, bboxes: [bbox], draw: draw }];
    }
}
exports.TextSymbolizer = TextSymbolizer;
class CenteredTextSymbolizer {
    constructor(options) {
        this.centered = new CenteredSymbolizer(new TextSymbolizer(options));
    }
    place(layout, geom, feature) {
        return this.centered.place(layout, geom, feature);
    }
}
exports.CenteredTextSymbolizer = CenteredTextSymbolizer;
class OffsetSymbolizer {
    constructor(symbolizer, options) {
        var _a, _b, _c;
        this.symbolizer = symbolizer;
        this.offsetX = new attribute_1.NumberAttr(options.offsetX, 0);
        this.offsetY = new attribute_1.NumberAttr(options.offsetY, 0);
        this.justify = (_a = options.justify) !== null && _a !== void 0 ? _a : undefined;
        this.placements = (_b = options.placements) !== null && _b !== void 0 ? _b : [
            TextPlacements.Ne,
            TextPlacements.Sw,
            TextPlacements.Nw,
            TextPlacements.Se,
            TextPlacements.N,
            TextPlacements.E,
            TextPlacements.S,
            TextPlacements.W,
        ];
        this.ddValues =
            (_c = options.ddValues) !== null && _c !== void 0 ? _c : (() => {
                return {};
            });
    }
    place(layout, geom, feature) {
        if (feature.geomType !== tilecache_1.GeomType.Point)
            return undefined;
        const anchor = geom[0][0];
        const placed = this.symbolizer.place(layout, [[new point_geometry_1.default(0, 0)]], feature);
        if (!placed || placed.length === 0)
            return undefined;
        const firstLabel = placed[0];
        const fb = firstLabel.bboxes[0];
        let offsetXvalue = this.offsetX;
        let offsetYvalue = this.offsetY;
        let justifyValue = this.justify;
        let placements = this.placements;
        const { offsetX: ddOffsetX, offsetY: ddOffsetY, justify: ddJustify, placements: ddPlacements, } = this.ddValues(layout.zoom, feature) || {};
        if (ddOffsetX)
            offsetXvalue = new attribute_1.NumberAttr(ddOffsetX, 0);
        if (ddOffsetY)
            offsetYvalue = new attribute_1.NumberAttr(ddOffsetY, 0);
        if (ddJustify)
            justifyValue = ddJustify;
        if (ddPlacements)
            placements = ddPlacements;
        const offsetX = offsetXvalue.get(layout.zoom, feature);
        const offsetY = offsetYvalue.get(layout.zoom, feature);
        const getBbox = (a, o) => {
            return {
                minX: a.x + o.x + fb.minX,
                minY: a.y + o.y + fb.minY,
                maxX: a.x + o.x + fb.maxX,
                maxY: a.y + o.y + fb.maxY,
            };
        };
        let origin = new point_geometry_1.default(offsetX, offsetY);
        let justify;
        const draw = (ctx) => {
            ctx.translate(origin.x, origin.y);
            firstLabel.draw(ctx, { justify: justify });
        };
        const placeLabelInPoint = (a, o) => {
            const bbox = getBbox(a, o);
            if (!layout.index.bboxCollides(bbox, layout.order))
                return [{ anchor: anchor, bboxes: [bbox], draw: draw }];
        };
        for (const placement of placements) {
            const xAxisOffset = this.computeXaxisOffset(offsetX, fb, placement);
            const yAxisOffset = this.computeYaxisOffset(offsetY, fb, placement);
            justify = this.computeJustify(justifyValue, placement);
            origin = new point_geometry_1.default(xAxisOffset, yAxisOffset);
            return placeLabelInPoint(anchor, origin);
        }
        return undefined;
    }
    computeXaxisOffset(offsetX, fb, placement) {
        const labelWidth = fb.maxX;
        const labelHalfWidth = labelWidth / 2;
        if ([TextPlacements.N, TextPlacements.S].includes(placement))
            return offsetX - labelHalfWidth;
        if ([TextPlacements.Nw, TextPlacements.W, TextPlacements.Sw].includes(placement))
            return offsetX - labelWidth;
        return offsetX;
    }
    computeYaxisOffset(offsetY, fb, placement) {
        const labelHalfHeight = Math.abs(fb.minY);
        const labelBottom = fb.maxY;
        const labelCenterHeight = (fb.minY + fb.maxY) / 2;
        if ([TextPlacements.E, TextPlacements.W].includes(placement))
            return offsetY - labelCenterHeight;
        if ([TextPlacements.Nw, TextPlacements.Ne, TextPlacements.N].includes(placement))
            return offsetY - labelBottom;
        if ([TextPlacements.Sw, TextPlacements.Se, TextPlacements.S].includes(placement))
            return offsetY + labelHalfHeight;
        return offsetY;
    }
    computeJustify(fixedJustify, placement) {
        if (fixedJustify)
            return fixedJustify;
        if ([TextPlacements.N, TextPlacements.S].includes(placement))
            return Justify.Center;
        if ([TextPlacements.Ne, TextPlacements.E, TextPlacements.Se].includes(placement))
            return Justify.Left;
        return Justify.Right;
    }
}
exports.OffsetSymbolizer = OffsetSymbolizer;
class OffsetTextSymbolizer {
    constructor(options) {
        this.symbolizer = new OffsetSymbolizer(new TextSymbolizer(options), options);
    }
    place(layout, geom, feature) {
        return this.symbolizer.place(layout, geom, feature);
    }
}
exports.OffsetTextSymbolizer = OffsetTextSymbolizer;
var LineLabelPlacement;
(function (LineLabelPlacement) {
    LineLabelPlacement[LineLabelPlacement["Above"] = 1] = "Above";
    LineLabelPlacement[LineLabelPlacement["Center"] = 2] = "Center";
    LineLabelPlacement[LineLabelPlacement["Below"] = 3] = "Below";
})(LineLabelPlacement || (exports.LineLabelPlacement = LineLabelPlacement = {}));
class LineLabelSymbolizer {
    constructor(options) {
        var _a;
        this.font = new attribute_1.FontAttr(options);
        this.text = new attribute_1.TextAttr(options);
        this.fill = new attribute_1.StringAttr(options.fill, "black");
        this.stroke = new attribute_1.StringAttr(options.stroke, "black");
        this.width = new attribute_1.NumberAttr(options.width, 0);
        this.offset = new attribute_1.NumberAttr(options.offset, 0);
        this.position = (_a = options.position) !== null && _a !== void 0 ? _a : LineLabelPlacement.Above;
        this.maxLabelCodeUnits = new attribute_1.NumberAttr(options.maxLabelChars, 40);
        this.repeatDistance = new attribute_1.NumberAttr(options.repeatDistance, 250);
    }
    place(layout, geom, feature) {
        const name = this.text.get(layout.zoom, feature);
        if (!name)
            return undefined;
        if (name.length > this.maxLabelCodeUnits.get(layout.zoom, feature))
            return undefined;
        const minLabelableDim = 20;
        const fbbox = feature.bbox;
        if (fbbox.maxY - fbbox.minY < minLabelableDim &&
            fbbox.maxX - fbbox.minX < minLabelableDim)
            return undefined;
        const font = this.font.get(layout.zoom, feature);
        layout.scratch.font = font;
        const metrics = layout.scratch.measureText(name);
        const width = metrics.width;
        const height = metrics.actualBoundingBoxAscent + metrics.actualBoundingBoxDescent;
        let repeatDistance = this.repeatDistance.get(layout.zoom, feature);
        if (layout.overzoom > 4)
            repeatDistance *= 1 << (layout.overzoom - 4);
        const cellSize = height * 2;
        const labelCandidates = (0, line_1.simpleLabel)(geom, width, repeatDistance, cellSize);
        if (labelCandidates.length === 0)
            return undefined;
        const labels = [];
        for (const candidate of labelCandidates) {
            const dx = candidate.end.x - candidate.start.x;
            const dy = candidate.end.y - candidate.start.y;
            const cells = (0, line_1.lineCells)(candidate.start, candidate.end, width, cellSize / 2);
            const bboxes = cells.map((c) => {
                return {
                    minX: c.x - cellSize / 2,
                    minY: c.y - cellSize / 2,
                    maxX: c.x + cellSize / 2,
                    maxY: c.y + cellSize / 2,
                };
            });
            const draw = (ctx) => {
                ctx.globalAlpha = 1;
                ctx.rotate(Math.atan2(dy, dx));
                if (dx < 0) {
                    ctx.scale(-1, -1);
                    ctx.translate(-width, 0);
                }
                let heightPlacement = 0;
                if (this.position === LineLabelPlacement.Below)
                    heightPlacement += height;
                else if (this.position === LineLabelPlacement.Center)
                    heightPlacement += height / 2;
                ctx.translate(0, heightPlacement - this.offset.get(layout.zoom, feature));
                ctx.font = font;
                const lineWidth = this.width.get(layout.zoom, feature);
                if (lineWidth) {
                    ctx.lineWidth = lineWidth;
                    ctx.strokeStyle = this.stroke.get(layout.zoom, feature);
                    ctx.strokeText(name, 0, 0);
                }
                ctx.fillStyle = this.fill.get(layout.zoom, feature);
                ctx.fillText(name, 0, 0);
            };
            labels.push({
                anchor: candidate.start,
                bboxes: bboxes,
                draw: draw,
                deduplicationKey: name,
                deduplicationDistance: repeatDistance,
            });
        }
        return labels;
    }
}
exports.LineLabelSymbolizer = LineLabelSymbolizer;

},
/* protomaps-leaflet/src/task.ts */
4: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.Sheet = exports.Font = void 0;
const potpack_1 = __importDefault(__req(15));
const Font = (name, url, weight) => {
    const ff = new FontFace(name, `url(${url})`, { weight: weight });
    document.fonts.add(ff);
    return ff.load();
};
exports.Font = Font;
const mkimg = async (src) => {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject("Invalid SVG");
        img.src = src;
    });
};
const MISSING = `
<svg width="20px" height="20px" viewBox="0 0 50 50" version="1.1" xmlns="http://www.w3.org/2000/svg">
    <rect width="50" height="50" fill="#cccccc"/>
    <g transform="translate(5,5)">
        <path fill="none" stroke="#666666" stroke-width="7" d="m11,12a8.5,8 0 1,1 17,0q0,4-4,6t-4.5,4.5-.4,4v.2m0,3v7"/>
    </g>
</svg>
`;
class Sheet {
    constructor(src) {
        this.src = src;
        this.canvas = document.createElement("canvas");
        this.mapping = new Map();
        this.missingBox = { x: 0, y: 0, w: 0, h: 0 };
    }
    async load() {
        let src = this.src;
        const scale = window.devicePixelRatio;
        if (src.endsWith(".html")) {
            const c = await fetch(src);
            src = await c.text();
        }
        const tree = new window.DOMParser().parseFromString(src, "text/html");
        const icons = Array.from(tree.body.children);
        const missingImg = await mkimg(`data:image/svg+xml;base64,${btoa(MISSING)}`);
        const boxes = [
            {
                w: missingImg.width * scale,
                h: missingImg.height * scale,
                img: missingImg,
                id: "",
            },
        ];
        const serializer = new XMLSerializer();
        for (const ps of icons) {
            const svg64 = btoa(serializer.serializeToString(ps));
            const image64 = `data:image/svg+xml;base64,${svg64}`;
            const img = await mkimg(image64);
            boxes.push({
                w: img.width * scale,
                h: img.height * scale,
                img: img,
                id: ps.id,
            });
        }
        const packresult = (0, potpack_1.default)(boxes);
        this.canvas.width = packresult.w;
        this.canvas.height = packresult.h;
        const ctx = this.canvas.getContext("2d");
        if (ctx) {
            for (const box of boxes) {
                if (box.x !== undefined && box.y !== undefined) {
                    ctx.drawImage(box.img, box.x, box.y, box.w, box.h);
                    if (box.id)
                        this.mapping.set(box.id, {
                            x: box.x,
                            y: box.y,
                            w: box.w,
                            h: box.h,
                        });
                    else
                        this.missingBox = { x: box.x, y: box.y, w: box.w, h: box.h };
                }
            }
        }
        return this;
    }
    get(name) {
        let result = this.mapping.get(name);
        if (!result)
            result = this.missingBox;
        return result;
    }
}
exports.Sheet = Sheet;

},
/* protomaps-leaflet/src/default_style/style.ts */
5: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.labelRules = exports.paintRules = void 0;
const color2k_1 = __req(16);
const symbolizer_1 = __req(3);
const tilecache_1 = __req(7);
const getString = (props, key) => {
    const val = props[key];
    if (typeof val === "string")
        return val;
    return "";
};
const getNumber = (props, key) => {
    const val = props[key];
    if (typeof val === "number")
        return val;
    return 0;
};
const paintRules = (t) => {
    return [
        {
            dataLayer: "earth",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.earth,
            }),
        },
        ...(t.landcover
            ? [
                {
                    dataLayer: "landcover",
                    symbolizer: new symbolizer_1.PolygonSymbolizer({
                        fill: (z, f) => {
                            const landcover = t.landcover;
                            if (!landcover || !f)
                                return "";
                            const kind = getString(f.props, "kind");
                            if (kind === "grassland")
                                return landcover.grassland;
                            if (kind === "barren")
                                return landcover.barren;
                            if (kind === "urban_area")
                                return landcover.urban_area;
                            if (kind === "farmland")
                                return landcover.farmland;
                            if (kind === "glacier")
                                return landcover.glacier;
                            if (kind === "scrub")
                                return landcover.scrub;
                            return landcover.forest;
                        },
                        opacity: (z, f) => {
                            if (z === 8)
                                return 0.5;
                            return 1;
                        },
                    }),
                },
            ]
            : []),
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: (z, f) => {
                    return (0, color2k_1.mix)(t.park_a, t.park_b, Math.min(Math.max(z / 12.0, 12), 0));
                },
            }),
            filter: (z, f) => {
                const kind = getString(f.props, "kind");
                return ["allotments", "village_green", "playground"].includes(kind);
            },
        },
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.park_b,
                opacity: (z, f) => {
                    if (z < 8)
                        return 0;
                    if (z === 8)
                        return 0.5;
                    return 1;
                },
            }),
            filter: (z, f) => {
                const kind = getString(f.props, "kind");
                return [
                    "national_park",
                    "park",
                    "cemetery",
                    "protected_area",
                    "nature_reserve",
                    "forest",
                    "golf_course",
                ].includes(kind);
            },
        },
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.hospital,
            }),
            filter: (z, f) => {
                return f.props.kind === "hospital";
            },
        },
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.industrial,
            }),
            filter: (z, f) => {
                return f.props.kind === "industrial";
            },
        },
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.school,
            }),
            filter: (z, f) => {
                const kind = getString(f.props, "kind");
                return ["school", "university", "college"].includes(kind);
            },
        },
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.beach,
            }),
            filter: (z, f) => {
                return f.props.kind === "beach";
            },
        },
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.zoo,
            }),
            filter: (z, f) => {
                return f.props.kind === "zoo";
            },
        },
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.zoo,
            }),
            filter: (z, f) => {
                const kind = getString(f.props, "kind");
                return ["military", "naval_base", "airfield"].includes(kind);
            },
        },
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: (z, f) => {
                    return (0, color2k_1.mix)(t.wood_a, t.wood_b, Math.min(Math.max(z / 12.0, 12), 0));
                },
                opacity: (z, f) => {
                    if (z < 8)
                        return 0;
                    if (z === 8)
                        return 0.5;
                    return 1;
                },
            }),
            filter: (z, f) => {
                const kind = getString(f.props, "kind");
                return ["wood", "nature_reserve", "forest"].includes(kind);
            },
        },
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: (z, f) => {
                    return (0, color2k_1.mix)(t.scrub_a, t.scrub_b, Math.min(Math.max(z / 12.0, 12), 0));
                },
            }),
            filter: (z, f) => {
                const kind = getString(f.props, "kind");
                return ["scrub", "grassland", "grass"].includes(kind);
            },
        },
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.scrub_b,
            }),
            filter: (z, f) => {
                const kind = getString(f.props, "kind");
                return ["scrub", "grassland", "grass"].includes(kind);
            },
        },
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.glacier,
            }),
            filter: (z, f) => {
                return f.props.kind === "glacier";
            },
        },
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.sand,
                opacity: (z, f) => {
                    if (z < 8)
                        return 0;
                    if (z === 8)
                        return 0.5;
                    return 1;
                },
            }),
            filter: (z, f) => {
                return f.props.kind === "sand";
            },
        },
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.aerodrome,
            }),
            filter: (z, f) => {
                return f.props.kind === "aerodrome";
            },
        },
        {
            dataLayer: "water",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.water,
            }),
            filter: (z, f) => {
                return f.geomType === tilecache_1.GeomType.Polygon;
            },
        },
        {
            dataLayer: "roads",
            symbolizer: new symbolizer_1.LineSymbolizer({
                color: t.runway,
                width: (z, f) => {
                    return (0, symbolizer_1.exp)(1.6, [
                        [11, 0],
                        [13, 4],
                        [19, 30],
                    ])(z);
                },
            }),
            filter: (z, f) => {
                return f.props.kind_detail === "runway";
            },
        },
        {
            dataLayer: "roads",
            symbolizer: new symbolizer_1.LineSymbolizer({
                color: t.runway,
                width: (z, f) => {
                    return (0, symbolizer_1.exp)(1.6, [
                        [14, 0],
                        [14.5, 1],
                        [16, 6],
                    ])(z);
                },
            }),
            filter: (z, f) => {
                return f.props.kind_detail === "taxiway";
            },
        },
        {
            dataLayer: "roads",
            symbolizer: new symbolizer_1.LineSymbolizer({
                color: t.pier,
                width: (z, f) => {
                    return (0, symbolizer_1.exp)(1.6, [
                        [13, 0],
                        [13.5, 0, 5],
                        [21, 16],
                    ])(z);
                },
            }),
            filter: (z, f) => {
                return f.props.kind === "path" && f.props.kind_detail === "pier";
            },
        },
        {
            dataLayer: "water",
            minzoom: 14,
            symbolizer: new symbolizer_1.LineSymbolizer({
                color: t.water,
                width: (z, f) => {
                    return (0, symbolizer_1.exp)(1.6, [
                        [9, 0],
                        [9.5, 1.0],
                        [18, 12],
                    ])(z);
                },
            }),
            filter: (z, f) => {
                return f.geomType === tilecache_1.GeomType.Line && f.props.kind === "river";
            },
        },
        {
            dataLayer: "water",
            minzoom: 14,
            symbolizer: new symbolizer_1.LineSymbolizer({
                color: t.water,
                width: 0.5,
            }),
            filter: (z, f) => {
                return f.geomType === tilecache_1.GeomType.Line && f.props.kind === "stream";
            },
        },
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.pedestrian,
            }),
            filter: (z, f) => {
                return f.props.kind === "pedestrian";
            },
        },
        {
            dataLayer: "landuse",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.pier,
            }),
            filter: (z, f) => {
                return f.props.kind === "pier";
            },
        },
        {
            dataLayer: "buildings",
            symbolizer: new symbolizer_1.PolygonSymbolizer({
                fill: t.buildings,
                opacity: 0.5,
            }),
        },
        {
            dataLayer: "roads",
            symbolizer: new symbolizer_1.LineSymbolizer({
                color: t.major,
                width: (z, f) => {
                    return (0, symbolizer_1.exp)(1.6, [
                        [14, 0],
                        [20, 7],
                    ])(z);
                },
            }),
            filter: (z, f) => {
                const kind = getString(f.props, "kind");
                return ["other", "path"].includes(kind);
            },
        },
        {
            dataLayer: "roads",
            symbolizer: new symbolizer_1.LineSymbolizer({
                color: t.major,
                width: (z, f) => {
                    return (0, symbolizer_1.exp)(1.6, [
                        [13, 0],
                        [18, 8],
                    ])(z);
                },
            }),
            filter: (z, f) => {
                return f.props.kind === "minor_road";
            },
        },
        {
            dataLayer: "roads",
            symbolizer: new symbolizer_1.LineSymbolizer({
                color: t.major,
                width: (z, f) => {
                    return (0, symbolizer_1.exp)(1.6, [
                        [6, 0],
                        [12, 1.6],
                        [15, 3],
                        [18, 13],
                    ])(z);
                },
            }),
            filter: (z, f) => {
                return f.props.kind === "major_road";
            },
        },
        {
            dataLayer: "roads",
            symbolizer: new symbolizer_1.LineSymbolizer({
                color: t.major,
                width: (z, f) => {
                    return (0, symbolizer_1.exp)(1.6, [
                        [3, 0],
                        [6, 1.1],
                        [12, 1.6],
                        [15, 5],
                        [18, 15],
                    ])(z);
                },
            }),
            filter: (z, f) => {
                return f.props.kind === "highway";
            },
        },
        {
            dataLayer: "boundaries",
            symbolizer: new symbolizer_1.LineSymbolizer({
                color: t.boundaries,
                width: 1,
            }),
            filter: (z, f) => {
                const minAdminLevel = f.props.kind_detail;
                return typeof minAdminLevel === "number" && minAdminLevel <= 2;
            },
        },
        {
            dataLayer: "roads",
            symbolizer: new symbolizer_1.LineSymbolizer({
                dash: [0.3, 0.75],
                color: t.railway,
                dashWidth: (z, f) => {
                    return (0, symbolizer_1.exp)(1.6, [
                        [4, 0],
                        [7, 0.15],
                        [19, 9],
                    ])(z);
                },
                opacity: 0.5,
            }),
            filter: (z, f) => {
                return f.props.kind === "rail";
            },
        },
        {
            dataLayer: "boundaries",
            symbolizer: new symbolizer_1.LineSymbolizer({
                color: t.boundaries,
                width: 0.5,
            }),
            filter: (z, f) => {
                const minAdminLevel = f.props.kind_detail;
                return typeof minAdminLevel === "number" && minAdminLevel > 2;
            },
        },
    ];
};
exports.paintRules = paintRules;
const labelRules = (t, lang) => {
    const nametags = [`name:${lang}`, "name"];
    return [
        {
            dataLayer: "roads",
            symbolizer: new symbolizer_1.LineLabelSymbolizer({
                labelProps: nametags,
                fill: t.roads_label_minor,
                font: "400 12px sans-serif",
                width: 2,
                stroke: t.roads_label_minor_halo,
            }),
            minzoom: 16,
            filter: (z, f) => {
                const kind = getString(f.props, "kind");
                return ["minor_road", "other", "path"].includes(kind);
            },
        },
        {
            dataLayer: "roads",
            symbolizer: new symbolizer_1.LineLabelSymbolizer({
                labelProps: nametags,
                fill: t.roads_label_major,
                font: "400 12px sans-serif",
                width: 2,
                stroke: t.roads_label_major_halo,
            }),
            minzoom: 12,
            filter: (z, f) => {
                const kind = getString(f.props, "kind");
                return ["highway", "major_road"].includes(kind);
            },
        },
        {
            dataLayer: "roads",
            symbolizer: new symbolizer_1.LineLabelSymbolizer({
                labelProps: nametags,
                fill: t.roads_label_major,
                font: "400 12px sans-serif",
                width: 2,
                stroke: t.roads_label_major_halo,
            }),
            minzoom: 12,
            filter: (z, f) => {
                const kind = getString(f.props, "kind");
                return ["highway", "major_road"].includes(kind);
            },
        },
        {
            dataLayer: "water",
            symbolizer: new symbolizer_1.CenteredTextSymbolizer({
                labelProps: nametags,
                fill: t.ocean_label,
                lineHeight: 1.5,
                letterSpacing: 1,
                font: (z, f) => {
                    const size = (0, symbolizer_1.linear)([
                        [3, 10],
                        [10, 12],
                    ])(z);
                    return `400 ${size}px sans-serif`;
                },
                textTransform: "uppercase",
            }),
            filter: (z, f) => {
                const kind = getString(f.props, "kind");
                return (f.geomType === tilecache_1.GeomType.Point &&
                    ["ocean", "bay", "strait", "fjord"].includes(kind));
            },
        },
        {
            dataLayer: "water",
            symbolizer: new symbolizer_1.CenteredTextSymbolizer({
                labelProps: nametags,
                fill: t.ocean_label,
                lineHeight: 1.5,
                letterSpacing: 1,
                font: (z, f) => {
                    const size = (0, symbolizer_1.linear)([
                        [3, 10],
                        [6, 12],
                        [10, 12],
                    ])(z);
                    return `400 ${size}px sans-serif`;
                },
            }),
            filter: (z, f) => {
                const kind = getString(f.props, "kind");
                return (f.geomType === tilecache_1.GeomType.Point &&
                    ["sea", "lake", "water"].includes(kind));
            },
        },
        {
            dataLayer: "places",
            symbolizer: new symbolizer_1.CenteredTextSymbolizer({
                labelProps: (z, f) => {
                    if (z < 6) {
                        return [`ref:${lang}`, "ref"];
                    }
                    return nametags;
                },
                fill: t.state_label,
                stroke: t.state_label_halo,
                width: 1,
                lineHeight: 1.5,
                font: (z, f) => {
                    return "400 12px sans-serif";
                },
                textTransform: "uppercase",
            }),
            filter: (z, f) => {
                return f.props.kind === "region";
            },
        },
        {
            dataLayer: "places",
            symbolizer: new symbolizer_1.CenteredTextSymbolizer({
                labelProps: nametags,
                fill: t.country_label,
                lineHeight: 1.5,
                font: (z, f) => {
                    if (z < 6)
                        return "600 12px sans-serif";
                    return "600 12px sans-serif";
                },
                textTransform: "uppercase",
            }),
            filter: (z, f) => {
                return f.props.kind === "country";
            },
        },
        {
            dataLayer: "places",
            minzoom: 9,
            symbolizer: new symbolizer_1.CenteredTextSymbolizer({
                labelProps: nametags,
                fill: t.city_label,
                lineHeight: 1.5,
                font: (z, f) => {
                    if (!f)
                        return "400 12px sans-serif";
                    const minZoom = f.props.min_zoom;
                    let weight = 400;
                    if (minZoom && minZoom <= 5) {
                        weight = 600;
                    }
                    let size = 12;
                    const popRank = f.props.population_rank;
                    if (popRank && popRank > 9) {
                        size = 16;
                    }
                    return `${weight} ${size}px sans-serif`;
                },
            }),
            sort: (a, b) => {
                const aRank = getNumber(a, "min_zoom");
                const bRank = getNumber(b, "min_zoom");
                return aRank - bRank;
            },
            filter: (z, f) => {
                return f.props.kind === "locality";
            },
        },
        {
            dataLayer: "places",
            maxzoom: 8,
            symbolizer: new symbolizer_1.GroupSymbolizer([
                new symbolizer_1.CircleSymbolizer({
                    radius: 2,
                    fill: t.city_label,
                    stroke: t.city_label_halo,
                    width: 1.5,
                }),
                new symbolizer_1.OffsetTextSymbolizer({
                    labelProps: nametags,
                    fill: t.city_label,
                    stroke: t.city_label_halo,
                    width: 1,
                    offsetX: 6,
                    offsetY: 4.5,
                    font: (z, f) => {
                        return "400 12px sans-serif";
                    },
                }),
            ]),
            filter: (z, f) => {
                return f.props.kind === "locality";
            },
        },
    ];
};
exports.labelRules = labelRules;

},
/* protomaps-leaflet/src/painter.ts */
6: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.paint = paint;
const point_geometry_1 = __importDefault(__req(10));
const view_1 = __req(8);
function paint(ctx, z, preparedTilemap, labelData, rules, bbox, origin, clip, debug) {
    const start = performance.now();
    ctx.save();
    ctx.miterLimit = 2;
    for (const rule of rules) {
        if (rule.minzoom && z < rule.minzoom)
            continue;
        if (rule.maxzoom && z > rule.maxzoom)
            continue;
        const preparedTiles = preparedTilemap.get(rule.dataSource || "");
        if (!preparedTiles)
            continue;
        for (const preparedTile of preparedTiles) {
            const layer = preparedTile.data.get(rule.dataLayer);
            if (layer === undefined)
                continue;
            if (rule.symbolizer.before)
                rule.symbolizer.before(ctx, preparedTile.z);
            const po = preparedTile.origin;
            const dim = preparedTile.dim;
            const ps = preparedTile.scale;
            ctx.save();
            if (clip) {
                ctx.beginPath();
                const minX = Math.max(po.x - origin.x, bbox.minX - origin.x);
                const minY = Math.max(po.y - origin.y, bbox.minY - origin.y);
                const maxX = Math.min(po.x - origin.x + dim, bbox.maxX - origin.x);
                const maxY = Math.min(po.y - origin.y + dim, bbox.maxY - origin.y);
                ctx.rect(minX, minY, maxX - minX, maxY - minY);
                ctx.clip();
            }
            ctx.translate(po.x - origin.x, po.y - origin.y);
            for (const feature of layer) {
                let geom = feature.geom;
                const fbox = feature.bbox;
                if (fbox.maxX * ps + po.x < bbox.minX ||
                    fbox.minX * ps + po.x > bbox.maxX ||
                    fbox.minY * ps + po.y > bbox.maxY ||
                    fbox.maxY * ps + po.y < bbox.minY) {
                    continue;
                }
                if (rule.filter && !rule.filter(preparedTile.z, feature))
                    continue;
                if (ps !== 1) {
                    geom = (0, view_1.transformGeom)(geom, ps, new point_geometry_1.default(0, 0));
                }
                rule.symbolizer.draw(ctx, geom, preparedTile.z, feature);
            }
            ctx.restore();
        }
    }
    if (clip) {
        ctx.beginPath();
        ctx.rect(bbox.minX - origin.x, bbox.minY - origin.y, bbox.maxX - bbox.minX, bbox.maxY - bbox.minY);
        ctx.clip();
    }
    if (labelData) {
        const matches = labelData.searchBbox(bbox, Infinity);
        for (const label of matches) {
            ctx.save();
            ctx.translate(label.anchor.x - origin.x, label.anchor.y - origin.y);
            label.draw(ctx);
            ctx.restore();
            if (debug) {
                ctx.lineWidth = 0.5;
                ctx.strokeStyle = debug;
                ctx.fillStyle = debug;
                ctx.globalAlpha = 1;
                ctx.fillRect(label.anchor.x - origin.x - 2, label.anchor.y - origin.y - 2, 4, 4);
                for (const bbox of label.bboxes) {
                    ctx.strokeRect(bbox.minX - origin.x, bbox.minY - origin.y, bbox.maxX - bbox.minX, bbox.maxY - bbox.minY);
                }
            }
        }
    }
    ctx.restore();
    return performance.now() - start;
}

},
/* protomaps-leaflet/src/tilecache.ts */
7: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.TileCache = exports.ZxySource = exports.PmtilesSource = exports.GeomType = void 0;
exports.toIndex = toIndex;
exports.isInRing = isInRing;
exports.isCcw = isCcw;
exports.pointInPolygon = pointInPolygon;
exports.pointMinDistToPoints = pointMinDistToPoints;
exports.pointMinDistToLines = pointMinDistToLines;
const point_geometry_1 = __importDefault(__req(10));
const vector_tile_1 = __req(17);
const pbf_1 = __importDefault(__req(18));
const pmtiles_1 = __req(19);
var GeomType;
(function (GeomType) {
    GeomType[GeomType["Point"] = 1] = "Point";
    GeomType[GeomType["Line"] = 2] = "Line";
    GeomType[GeomType["Polygon"] = 3] = "Polygon";
})(GeomType || (exports.GeomType = GeomType = {}));
function toIndex(c) {
    return `${c.x}:${c.y}:${c.z}`;
}
const loadGeomAndBbox = (pbf, geometry, scale) => {
    pbf.pos = geometry;
    const end = pbf.readVarint() + pbf.pos;
    let cmd = 1;
    let length = 0;
    let x = 0;
    let y = 0;
    let x1 = Infinity;
    let x2 = -Infinity;
    let y1 = Infinity;
    let y2 = -Infinity;
    const lines = [];
    let line = [];
    while (pbf.pos < end) {
        if (length <= 0) {
            const cmdLen = pbf.readVarint();
            cmd = cmdLen & 0x7;
            length = cmdLen >> 3;
        }
        length--;
        if (cmd === 1 || cmd === 2) {
            x += pbf.readSVarint() * scale;
            y += pbf.readSVarint() * scale;
            if (x < x1)
                x1 = x;
            if (x > x2)
                x2 = x;
            if (y < y1)
                y1 = y;
            if (y > y2)
                y2 = y;
            if (cmd === 1) {
                if (line.length > 0)
                    lines.push(line);
                line = [];
            }
            line.push(new point_geometry_1.default(x, y));
        }
        else if (cmd === 7) {
            if (line)
                line.push(line[0].clone());
        }
        else
            throw new Error(`unknown command ${cmd}`);
    }
    if (line)
        lines.push(line);
    return { geom: lines, bbox: { minX: x1, minY: y1, maxX: x2, maxY: y2 } };
};
function parseTile(buffer, tileSize) {
    const v = new vector_tile_1.VectorTile(new pbf_1.default(buffer));
    const result = new Map();
    for (const [key, value] of Object.entries(v.layers)) {
        const features = [];
        const layer = value;
        for (let i = 0; i < layer.length; i++) {
            const loaded = loadGeomAndBbox(layer.feature(i)._pbf, layer.feature(i)._geometry, tileSize / layer.extent);
            let numVertices = 0;
            for (const part of loaded.geom)
                numVertices += part.length;
            features.push({
                id: layer.feature(i).id,
                geomType: layer.feature(i).type,
                geom: loaded.geom,
                numVertices: numVertices,
                bbox: loaded.bbox,
                props: layer.feature(i).properties,
            });
        }
        result.set(key, features);
    }
    return result;
}
class PmtilesSource {
    constructor(url, shouldCancelZooms) {
        if (typeof url === "string") {
            this.p = new pmtiles_1.PMTiles(url);
        }
        else {
            this.p = url;
        }
        this.zoomaborts = [];
        this.shouldCancelZooms = shouldCancelZooms;
    }
    async get(c, tileSize) {
        if (this.shouldCancelZooms) {
            this.zoomaborts = this.zoomaborts.filter((za) => {
                if (za.z !== c.z) {
                    za.controller.abort();
                    return false;
                }
                return true;
            });
        }
        const controller = new AbortController();
        this.zoomaborts.push({ z: c.z, controller: controller });
        const signal = controller.signal;
        const result = await this.p.getZxy(c.z, c.x, c.y, signal);
        if (result) {
            return parseTile(result.data, tileSize);
        }
        return new Map();
    }
}
exports.PmtilesSource = PmtilesSource;
class ZxySource {
    constructor(url, shouldCancelZooms) {
        this.url = url;
        this.zoomaborts = [];
        this.shouldCancelZooms = shouldCancelZooms;
    }
    async get(c, tileSize) {
        if (this.shouldCancelZooms) {
            this.zoomaborts = this.zoomaborts.filter((za) => {
                if (za.z !== c.z) {
                    za.controller.abort();
                    return false;
                }
                return true;
            });
        }
        const url = this.url
            .replace("{z}", c.z.toString())
            .replace("{x}", c.x.toString())
            .replace("{y}", c.y.toString());
        const controller = new AbortController();
        this.zoomaborts.push({ z: c.z, controller: controller });
        const signal = controller.signal;
        return new Promise((resolve, reject) => {
            fetch(url, { signal: signal })
                .then((resp) => {
                return resp.arrayBuffer();
            })
                .then((buffer) => {
                const result = parseTile(buffer, tileSize);
                resolve(result);
            })
                .catch((e) => {
                reject(e);
            });
        });
    }
}
exports.ZxySource = ZxySource;
const R = 6378137;
const MAX_LATITUDE = 85.0511287798;
const MAXCOORD = R * Math.PI;
const project = (latlng) => {
    const d = Math.PI / 180;
    const constrainedLat = Math.max(Math.min(MAX_LATITUDE, latlng[0]), -MAX_LATITUDE);
    const sin = Math.sin(constrainedLat * d);
    return new point_geometry_1.default(R * latlng[1] * d, (R * Math.log((1 + sin) / (1 - sin))) / 2);
};
function sqr(x) {
    return x * x;
}
function dist2(v, w) {
    return sqr(v.x - w.x) + sqr(v.y - w.y);
}
function distToSegmentSquared(p, v, w) {
    const l2 = dist2(v, w);
    if (l2 === 0)
        return dist2(p, v);
    let t = ((p.x - v.x) * (w.x - v.x) + (p.y - v.y) * (w.y - v.y)) / l2;
    t = Math.max(0, Math.min(1, t));
    return dist2(p, new point_geometry_1.default(v.x + t * (w.x - v.x), v.y + t * (w.y - v.y)));
}
function isInRing(point, ring) {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const xi = ring[i].x;
        const yi = ring[i].y;
        const xj = ring[j].x;
        const yj = ring[j].y;
        const intersect = yi > point.y !== yj > point.y &&
            point.x < ((xj - xi) * (point.y - yi)) / (yj - yi) + xi;
        if (intersect)
            inside = !inside;
    }
    return inside;
}
function isCcw(ring) {
    let area = 0;
    for (let i = 0; i < ring.length; i++) {
        const j = (i + 1) % ring.length;
        area += ring[i].x * ring[j].y;
        area -= ring[j].x * ring[i].y;
    }
    return area < 0;
}
function pointInPolygon(point, geom) {
    let isInCurrentExterior = false;
    for (const ring of geom) {
        if (isCcw(ring)) {
            if (isInRing(point, ring))
                isInCurrentExterior = false;
        }
        else {
            if (isInCurrentExterior)
                return true;
            if (isInRing(point, ring))
                isInCurrentExterior = true;
        }
    }
    return isInCurrentExterior;
}
function pointMinDistToPoints(point, geom) {
    let min = Infinity;
    for (const l of geom) {
        const dist = Math.sqrt(dist2(point, l[0]));
        if (dist < min)
            min = dist;
    }
    return min;
}
function pointMinDistToLines(point, geom) {
    let min = Infinity;
    for (const l of geom) {
        for (let i = 0; i < l.length - 1; i++) {
            const dist = Math.sqrt(distToSegmentSquared(point, l[i], l[i + 1]));
            if (dist < min)
                min = dist;
        }
    }
    return min;
}
class TileCache {
    constructor(source, tileSize) {
        this.source = source;
        this.cache = new Map();
        this.inflight = new Map();
        this.tileSize = tileSize;
    }
    async get(c) {
        const idx = toIndex(c);
        return new Promise((resolve, reject) => {
            const entry = this.cache.get(idx);
            if (entry) {
                entry.used = performance.now();
                resolve(entry.data);
            }
            else {
                const ifentry = this.inflight.get(idx);
                if (ifentry) {
                    ifentry.push({ resolve: resolve, reject: reject });
                }
                else {
                    this.inflight.set(idx, []);
                    this.source
                        .get(c, this.tileSize)
                        .then((tile) => {
                        this.cache.set(idx, { used: performance.now(), data: tile });
                        const ifentry2 = this.inflight.get(idx);
                        if (ifentry2) {
                            for (const f of ifentry2) {
                                f.resolve(tile);
                            }
                        }
                        this.inflight.delete(idx);
                        resolve(tile);
                        if (this.cache.size >= 64) {
                            let minUsed = +Infinity;
                            let minKey = undefined;
                            this.cache.forEach((value, key) => {
                                if (value.used < minUsed) {
                                    minUsed = value.used;
                                    minKey = key;
                                }
                            });
                            if (minKey)
                                this.cache.delete(minKey);
                        }
                    })
                        .catch((e) => {
                        const ifentry2 = this.inflight.get(idx);
                        if (ifentry2) {
                            for (const f of ifentry2) {
                                f.reject(e);
                            }
                        }
                        this.inflight.delete(idx);
                        reject(e);
                    });
                }
            }
        });
    }
    queryFeatures(lng, lat, zoom, brushSize) {
        const projected = project([lat, lng]);
        const normalized = new point_geometry_1.default((projected.x + MAXCOORD) / (MAXCOORD * 2), 1 - (projected.y + MAXCOORD) / (MAXCOORD * 2));
        if (normalized.x > 1)
            normalized.x = normalized.x - Math.floor(normalized.x);
        const onZoom = normalized.mult(1 << zoom);
        const tileX = Math.floor(onZoom.x);
        const tileY = Math.floor(onZoom.y);
        const idx = toIndex({ z: zoom, x: tileX, y: tileY });
        const retval = [];
        const entry = this.cache.get(idx);
        if (entry) {
            const center = new point_geometry_1.default((onZoom.x - tileX) * this.tileSize, (onZoom.y - tileY) * this.tileSize);
            for (const [layerName, layerArr] of entry.data.entries()) {
                for (const feature of layerArr) {
                    if (feature.geomType === GeomType.Point) {
                        if (pointMinDistToPoints(center, feature.geom) < brushSize) {
                            retval.push({ feature, layerName: layerName });
                        }
                    }
                    else if (feature.geomType === GeomType.Line) {
                        if (pointMinDistToLines(center, feature.geom) < brushSize) {
                            retval.push({ feature, layerName: layerName });
                        }
                    }
                    else {
                        if (pointInPolygon(center, feature.geom)) {
                            retval.push({ feature, layerName: layerName });
                        }
                    }
                }
            }
        }
        return retval;
    }
}
exports.TileCache = TileCache;

},
/* protomaps-leaflet/src/view.ts */
8: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.sourcesToViews = exports.View = exports.wrap = exports.transformGeom = void 0;
const point_geometry_1 = __importDefault(__req(10));
const tilecache_1 = __req(7);
const transformGeom = (geom, scale, translate) => {
    const retval = [];
    for (const arr of geom) {
        const loop = [];
        for (const coord of arr) {
            loop.push(coord.clone().mult(scale).add(translate));
        }
        retval.push(loop);
    }
    return retval;
};
exports.transformGeom = transformGeom;
const wrap = (val, z) => {
    const dim = 1 << z;
    if (val < 0)
        return dim + val;
    if (val >= dim)
        return val % dim;
    return val;
};
exports.wrap = wrap;
class View {
    constructor(tileCache, maxDataLevel, levelDiff) {
        this.tileCache = tileCache;
        this.maxDataLevel = maxDataLevel;
        this.levelDiff = levelDiff;
    }
    dataTilesForBounds(displayZoom, bounds) {
        const fractional = 2 ** displayZoom / 2 ** Math.ceil(displayZoom);
        const needed = [];
        let scale = 1;
        const dim = this.tileCache.tileSize;
        if (displayZoom < this.levelDiff) {
            scale = (1 / (1 << (this.levelDiff - displayZoom))) * fractional;
            needed.push({
                dataTile: { z: 0, x: 0, y: 0 },
                origin: new point_geometry_1.default(0, 0),
                scale: scale,
                dim: dim * scale,
            });
        }
        else if (displayZoom <= this.levelDiff + this.maxDataLevel) {
            const f = 1 << this.levelDiff;
            const basetileSize = 256 * fractional;
            const dataZoom = Math.ceil(displayZoom) - this.levelDiff;
            const mintileX = Math.floor(bounds.minX / f / basetileSize);
            const mintileY = Math.floor(bounds.minY / f / basetileSize);
            const maxtileX = Math.floor(bounds.maxX / f / basetileSize);
            const maxtileY = Math.floor(bounds.maxY / f / basetileSize);
            for (let tx = mintileX; tx <= maxtileX; tx++) {
                for (let ty = mintileY; ty <= maxtileY; ty++) {
                    const origin = new point_geometry_1.default(tx * f * basetileSize, ty * f * basetileSize);
                    needed.push({
                        dataTile: {
                            z: dataZoom,
                            x: (0, exports.wrap)(tx, dataZoom),
                            y: (0, exports.wrap)(ty, dataZoom),
                        },
                        origin: origin,
                        scale: fractional,
                        dim: dim * fractional,
                    });
                }
            }
        }
        else {
            const f = 1 << this.levelDiff;
            scale =
                (1 << (Math.ceil(displayZoom) - this.maxDataLevel - this.levelDiff)) *
                    fractional;
            const mintileX = Math.floor(bounds.minX / f / 256 / scale);
            const mintileY = Math.floor(bounds.minY / f / 256 / scale);
            const maxtileX = Math.floor(bounds.maxX / f / 256 / scale);
            const maxtileY = Math.floor(bounds.maxY / f / 256 / scale);
            for (let tx = mintileX; tx <= maxtileX; tx++) {
                for (let ty = mintileY; ty <= maxtileY; ty++) {
                    const origin = new point_geometry_1.default(tx * f * 256 * scale, ty * f * 256 * scale);
                    needed.push({
                        dataTile: {
                            z: this.maxDataLevel,
                            x: (0, exports.wrap)(tx, this.maxDataLevel),
                            y: (0, exports.wrap)(ty, this.maxDataLevel),
                        },
                        origin: origin,
                        scale: scale,
                        dim: dim * scale,
                    });
                }
            }
        }
        return needed;
    }
    dataTileForDisplayTile(displayTile) {
        let dataTile;
        let scale = 1;
        let dim = this.tileCache.tileSize;
        let origin;
        if (displayTile.z < this.levelDiff) {
            dataTile = { z: 0, x: 0, y: 0 };
            scale = 1 / (1 << (this.levelDiff - displayTile.z));
            origin = new point_geometry_1.default(0, 0);
            dim = dim * scale;
        }
        else if (displayTile.z <= this.levelDiff + this.maxDataLevel) {
            const f = 1 << this.levelDiff;
            dataTile = {
                z: displayTile.z - this.levelDiff,
                x: Math.floor(displayTile.x / f),
                y: Math.floor(displayTile.y / f),
            };
            origin = new point_geometry_1.default(dataTile.x * f * 256, dataTile.y * f * 256);
        }
        else {
            scale = 1 << (displayTile.z - this.maxDataLevel - this.levelDiff);
            const f = 1 << this.levelDiff;
            dataTile = {
                z: this.maxDataLevel,
                x: Math.floor(displayTile.x / f / scale),
                y: Math.floor(displayTile.y / f / scale),
            };
            origin = new point_geometry_1.default(dataTile.x * f * scale * 256, dataTile.y * f * scale * 256);
            dim = dim * scale;
        }
        return { dataTile: dataTile, scale: scale, origin: origin, dim: dim };
    }
    async getBbox(displayZoom, bounds) {
        const needed = this.dataTilesForBounds(displayZoom, bounds);
        const result = await Promise.all(needed.map((tt) => this.tileCache.get(tt.dataTile)));
        return result.map((data, i) => {
            const tt = needed[i];
            return {
                data: data,
                z: displayZoom,
                dataTile: tt.dataTile,
                scale: tt.scale,
                dim: tt.dim,
                origin: tt.origin,
            };
        });
    }
    async getDisplayTile(displayTile) {
        const tt = this.dataTileForDisplayTile(displayTile);
        const data = await this.tileCache.get(tt.dataTile);
        return {
            data: data,
            z: displayTile.z,
            dataTile: tt.dataTile,
            scale: tt.scale,
            origin: tt.origin,
            dim: tt.dim,
        };
    }
    queryFeatures(lng, lat, displayZoom, brushSize) {
        const roundedZoom = Math.round(displayZoom);
        const dataZoom = Math.min(roundedZoom - this.levelDiff, this.maxDataLevel);
        const brushSizeAtZoom = brushSize / (1 << (roundedZoom - dataZoom));
        return this.tileCache.queryFeatures(lng, lat, dataZoom, brushSizeAtZoom);
    }
}
exports.View = View;
const sourcesToViews = (options) => {
    const sourceToViews = (o) => {
        const levelDiff = o.levelDiff === undefined ? 1 : o.levelDiff;
        const maxDataZoom = o.maxDataZoom || 15;
        let source;
        if (typeof o.url === "string") {
            if (new URL(o.url, "http://example.com").pathname.endsWith(".pmtiles")) {
                source = new tilecache_1.PmtilesSource(o.url, true);
            }
            else {
                source = new tilecache_1.ZxySource(o.url, true);
            }
        }
        else if (o.url) {
            source = new tilecache_1.PmtilesSource(o.url, true);
        }
        else {
            throw new Error(`Invalid source ${o.url}`);
        }
        const cache = new tilecache_1.TileCache(source, (256 * 1) << levelDiff);
        return new View(cache, maxDataZoom, levelDiff);
    };
    const sources = new Map();
    if (options.sources) {
        for (const key in options.sources) {
            sources.set(key, sourceToViews(options.sources[key]));
        }
    }
    else {
        sources.set("", sourceToViews(options));
    }
    return sources;
};
exports.sourcesToViews = sourcesToViews;

},
/* protomaps-leaflet/src/labeler.ts */
9: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.Labelers = exports.Labeler = exports.Index = exports.covering = void 0;
const point_geometry_1 = __importDefault(__req(10));
const rbush_1 = __importDefault(__req(20));
const tilecache_1 = __req(7);
const view_1 = __req(8);
const covering = (displayZoom, tileWidth, bbox) => {
    const res = 256;
    const f = tileWidth / res;
    const minx = Math.floor(bbox.minX / res);
    const miny = Math.floor(bbox.minY / res);
    const maxx = Math.floor(bbox.maxX / res);
    const maxy = Math.floor(bbox.maxY / res);
    const leveldiff = Math.log2(f);
    const retval = [];
    for (let x = minx; x <= maxx; x++) {
        const wrappedX = x % (1 << displayZoom);
        for (let y = miny; y <= maxy; y++) {
            retval.push({
                display: (0, tilecache_1.toIndex)({ z: displayZoom, x: wrappedX, y: y }),
                key: (0, tilecache_1.toIndex)({
                    z: displayZoom - leveldiff,
                    x: Math.floor(wrappedX / f),
                    y: Math.floor(y / f),
                }),
            });
        }
    }
    return retval;
};
exports.covering = covering;
class Index {
    constructor(dim, maxLabeledTiles) {
        this.tree = new rbush_1.default();
        this.current = new Map();
        this.dim = dim;
        this.maxLabeledTiles = maxLabeledTiles;
    }
    hasPrefix(tileKey) {
        for (const key of this.current.keys()) {
            if (key.startsWith(tileKey))
                return true;
        }
        return false;
    }
    has(tileKey) {
        return this.current.has(tileKey);
    }
    size() {
        return this.current.size;
    }
    keys() {
        return this.current.keys();
    }
    searchBbox(bbox, order) {
        const labels = new Set();
        for (const match of this.tree.search(bbox)) {
            if (match.indexedLabel.order <= order) {
                labels.add(match.indexedLabel);
            }
        }
        return labels;
    }
    searchLabel(label, order) {
        const labels = new Set();
        for (const bbox of label.bboxes) {
            for (const match of this.tree.search(bbox)) {
                if (match.indexedLabel.order <= order) {
                    labels.add(match.indexedLabel);
                }
            }
        }
        return labels;
    }
    bboxCollides(bbox, order) {
        for (const match of this.tree.search(bbox)) {
            if (match.indexedLabel.order <= order)
                return true;
        }
        return false;
    }
    labelCollides(label, order) {
        for (const bbox of label.bboxes) {
            for (const match of this.tree.search(bbox)) {
                if (match.indexedLabel.order <= order)
                    return true;
            }
        }
        return false;
    }
    deduplicationCollides(label) {
        if (!label.deduplicationKey || !label.deduplicationDistance)
            return false;
        const dist = label.deduplicationDistance;
        const testBbox = {
            minX: label.anchor.x - dist,
            minY: label.anchor.y - dist,
            maxX: label.anchor.x + dist,
            maxY: label.anchor.y + dist,
        };
        for (const collision of this.tree.search(testBbox)) {
            if (collision.indexedLabel.deduplicationKey === label.deduplicationKey) {
                if (collision.indexedLabel.anchor.dist(label.anchor) < dist) {
                    return true;
                }
            }
        }
        return false;
    }
    makeEntry(tileKey) {
        if (this.current.get(tileKey)) {
            console.log("consistency error 1");
        }
        const newSet = new Set();
        this.current.set(tileKey, newSet);
    }
    insert(label, order, tileKey) {
        const indexedLabel = {
            anchor: label.anchor,
            bboxes: label.bboxes,
            draw: label.draw,
            order: order,
            tileKey: tileKey,
            deduplicationKey: label.deduplicationKey,
            deduplicationDistance: label.deduplicationDistance,
        };
        let entry = this.current.get(tileKey);
        if (!entry) {
            const newSet = new Set();
            this.current.set(tileKey, newSet);
            entry = newSet;
        }
        entry.add(indexedLabel);
        let wrapsLeft = false;
        let wrapsRight = false;
        for (const bbox of label.bboxes) {
            this.tree.insert({
                minX: bbox.minX,
                minY: bbox.minY,
                maxX: bbox.maxX,
                maxY: bbox.maxY,
                indexedLabel: indexedLabel,
            });
            if (bbox.minX < 0)
                wrapsLeft = true;
            if (bbox.maxX > this.dim)
                wrapsRight = true;
        }
        if (wrapsLeft || wrapsRight) {
            const shift = wrapsLeft ? this.dim : -this.dim;
            const newBboxes = [];
            for (const bbox of label.bboxes) {
                newBboxes.push({
                    minX: bbox.minX + shift,
                    minY: bbox.minY,
                    maxX: bbox.maxX + shift,
                    maxY: bbox.maxY,
                });
            }
            const duplicateLabel = {
                anchor: new point_geometry_1.default(label.anchor.x + shift, label.anchor.y),
                bboxes: newBboxes,
                draw: label.draw,
                order: order,
                tileKey: tileKey,
            };
            const entry = this.current.get(tileKey);
            if (entry)
                entry.add(duplicateLabel);
            for (const bbox of newBboxes) {
                this.tree.insert({
                    minX: bbox.minX,
                    minY: bbox.minY,
                    maxX: bbox.maxX,
                    maxY: bbox.maxY,
                    indexedLabel: duplicateLabel,
                });
            }
        }
    }
    pruneOrNoop(keyAdded) {
        const added = keyAdded.split(":");
        let maxKey = undefined;
        let maxDist = 0;
        let keysForDs = 0;
        for (const existingKey of this.current.keys()) {
            const existing = existingKey.split(":");
            if (existing[3] === added[3]) {
                keysForDs++;
                const dist = Math.sqrt((+existing[0] - +added[0]) ** 2 + (+existing[1] - +added[1]) ** 2);
                if (dist > maxDist) {
                    maxDist = dist;
                    maxKey = existingKey;
                }
            }
            if (maxKey && keysForDs > this.maxLabeledTiles) {
                this.pruneKey(maxKey);
            }
        }
    }
    pruneKey(keyToRemove) {
        const indexedLabels = this.current.get(keyToRemove);
        if (!indexedLabels)
            return;
        const entriesToDelete = [];
        for (const entry of this.tree.all()) {
            if (indexedLabels.has(entry.indexedLabel)) {
                entriesToDelete.push(entry);
            }
        }
        for (const entry of entriesToDelete) {
            this.tree.remove(entry);
        }
        this.current.delete(keyToRemove);
    }
    removeLabel(labelToRemove) {
        const entriesToDelete = [];
        for (const entry of this.tree.all()) {
            if (labelToRemove === entry.indexedLabel) {
                entriesToDelete.push(entry);
            }
        }
        for (const entry of entriesToDelete) {
            this.tree.remove(entry);
        }
        const c = this.current.get(labelToRemove.tileKey);
        if (c)
            c.delete(labelToRemove);
    }
}
exports.Index = Index;
class Labeler {
    constructor(z, scratch, labelRules, maxLabeledTiles, callback) {
        this.index = new Index((256 * 1) << z, maxLabeledTiles);
        this.z = z;
        this.scratch = scratch;
        this.labelRules = labelRules;
        this.callback = callback;
    }
    layout(preparedTilemap) {
        const start = performance.now();
        const keysAdding = new Set();
        for (const [k, preparedTiles] of preparedTilemap) {
            for (const preparedTile of preparedTiles) {
                const key = `${(0, tilecache_1.toIndex)(preparedTile.dataTile)}:${k}`;
                if (!this.index.has(key)) {
                    this.index.makeEntry(key);
                    keysAdding.add(key);
                }
            }
        }
        const tilesInvalidated = new Set();
        for (const [order, rule] of this.labelRules.entries()) {
            if (rule.visible === false)
                continue;
            if (rule.minzoom && this.z < rule.minzoom)
                continue;
            if (rule.maxzoom && this.z > rule.maxzoom)
                continue;
            const dsName = rule.dataSource || "";
            const preparedTiles = preparedTilemap.get(dsName);
            if (!preparedTiles)
                continue;
            for (const preparedTile of preparedTiles) {
                const key = `${(0, tilecache_1.toIndex)(preparedTile.dataTile)}:${dsName}`;
                if (!keysAdding.has(key))
                    continue;
                const layer = preparedTile.data.get(rule.dataLayer);
                if (layer === undefined)
                    continue;
                const feats = layer;
                if (rule.sort)
                    feats.sort((a, b) => {
                        if (rule.sort) {
                            return rule.sort(a.props, b.props);
                        }
                        return 0;
                    });
                const layout = {
                    index: this.index,
                    zoom: this.z,
                    scratch: this.scratch,
                    order: order,
                    overzoom: this.z - preparedTile.dataTile.z,
                };
                for (const feature of feats) {
                    if (rule.filter && !rule.filter(this.z, feature))
                        continue;
                    const transformed = (0, view_1.transformGeom)(feature.geom, preparedTile.scale, preparedTile.origin);
                    const labels = rule.symbolizer.place(layout, transformed, feature);
                    if (!labels)
                        continue;
                    for (const label of labels) {
                        let labelAdded = false;
                        if (label.deduplicationKey &&
                            this.index.deduplicationCollides(label)) {
                            continue;
                        }
                        if (this.index.labelCollides(label, Infinity)) {
                            if (!this.index.labelCollides(label, order)) {
                                const conflicts = this.index.searchLabel(label, Infinity);
                                for (const conflict of conflicts) {
                                    this.index.removeLabel(conflict);
                                    for (const bbox of conflict.bboxes) {
                                        this.findInvalidatedTiles(tilesInvalidated, preparedTile.dim, bbox, key);
                                    }
                                }
                                this.index.insert(label, order, key);
                                labelAdded = true;
                            }
                        }
                        else {
                            this.index.insert(label, order, key);
                            labelAdded = true;
                        }
                        if (labelAdded) {
                            for (const bbox of label.bboxes) {
                                if (bbox.maxX > preparedTile.origin.x + preparedTile.dim ||
                                    bbox.minX < preparedTile.origin.x ||
                                    bbox.minY < preparedTile.origin.y ||
                                    bbox.maxY > preparedTile.origin.y + preparedTile.dim) {
                                    this.findInvalidatedTiles(tilesInvalidated, preparedTile.dim, bbox, key);
                                }
                            }
                        }
                    }
                }
            }
        }
        for (const key of keysAdding) {
            this.index.pruneOrNoop(key);
        }
        if (tilesInvalidated.size > 0 && this.callback) {
            this.callback(tilesInvalidated);
        }
        return performance.now() - start;
    }
    findInvalidatedTiles(tilesInvalidated, dim, bbox, key) {
        const touched = (0, exports.covering)(this.z, dim, bbox);
        for (const s of touched) {
            if (s.key !== key && this.index.hasPrefix(s.key)) {
                tilesInvalidated.add(s.display);
            }
        }
    }
    add(preparedTilemap) {
        let allAdded = true;
        for (const [k, preparedTiles] of preparedTilemap) {
            for (const preparedTile of preparedTiles) {
                if (!this.index.has(`${(0, tilecache_1.toIndex)(preparedTile.dataTile)}:${k}`))
                    allAdded = false;
            }
        }
        if (allAdded) {
            return 0;
        }
        const timing = this.layout(preparedTilemap);
        return timing;
    }
}
exports.Labeler = Labeler;
class Labelers {
    constructor(scratch, labelRules, maxLabeledTiles, callback) {
        this.labelers = new Map();
        this.scratch = scratch;
        this.labelRules = labelRules;
        this.maxLabeledTiles = maxLabeledTiles;
        this.callback = callback;
    }
    add(z, preparedTilemap) {
        let labeler = this.labelers.get(z);
        if (labeler) {
            return labeler.add(preparedTilemap);
        }
        labeler = new Labeler(z, this.scratch, this.labelRules, this.maxLabeledTiles, this.callback);
        this.labelers.set(z, labeler);
        return labeler.add(preparedTilemap);
    }
    getIndex(z) {
        const labeler = this.labelers.get(z);
        if (labeler)
            return labeler.index;
    }
}
exports.Labelers = Labelers;

},
/* point-geometry/index.js */
10: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.default = Point;
function Point(x, y) {
    this.x = x;
    this.y = y;
}
Point.prototype = {
    clone() { return new Point(this.x, this.y); },
    add(p) { return this.clone()._add(p); },
    sub(p) { return this.clone()._sub(p); },
    multByPoint(p) { return this.clone()._multByPoint(p); },
    divByPoint(p) { return this.clone()._divByPoint(p); },
    mult(k) { return this.clone()._mult(k); },
    div(k) { return this.clone()._div(k); },
    rotate(a) { return this.clone()._rotate(a); },
    rotateAround(a, p) { return this.clone()._rotateAround(a, p); },
    matMult(m) { return this.clone()._matMult(m); },
    unit() { return this.clone()._unit(); },
    perp() { return this.clone()._perp(); },
    round() { return this.clone()._round(); },
    mag() {
        return Math.sqrt(this.x * this.x + this.y * this.y);
    },
    equals(other) {
        return this.x === other.x &&
            this.y === other.y;
    },
    dist(p) {
        return Math.sqrt(this.distSqr(p));
    },
    distSqr(p) {
        const dx = p.x - this.x, dy = p.y - this.y;
        return dx * dx + dy * dy;
    },
    angle() {
        return Math.atan2(this.y, this.x);
    },
    angleTo(b) {
        return Math.atan2(this.y - b.y, this.x - b.x);
    },
    angleWith(b) {
        return this.angleWithSep(b.x, b.y);
    },
    angleWithSep(x, y) {
        return Math.atan2(this.x * y - this.y * x, this.x * x + this.y * y);
    },
    _matMult(m) {
        const x = m[0] * this.x + m[1] * this.y, y = m[2] * this.x + m[3] * this.y;
        this.x = x;
        this.y = y;
        return this;
    },
    _add(p) {
        this.x += p.x;
        this.y += p.y;
        return this;
    },
    _sub(p) {
        this.x -= p.x;
        this.y -= p.y;
        return this;
    },
    _mult(k) {
        this.x *= k;
        this.y *= k;
        return this;
    },
    _div(k) {
        this.x /= k;
        this.y /= k;
        return this;
    },
    _multByPoint(p) {
        this.x *= p.x;
        this.y *= p.y;
        return this;
    },
    _divByPoint(p) {
        this.x /= p.x;
        this.y /= p.y;
        return this;
    },
    _unit() {
        this._div(this.mag());
        return this;
    },
    _perp() {
        const y = this.y;
        this.y = this.x;
        this.x = -y;
        return this;
    },
    _rotate(angle) {
        const cos = Math.cos(angle), sin = Math.sin(angle), x = cos * this.x - sin * this.y, y = sin * this.x + cos * this.y;
        this.x = x;
        this.y = y;
        return this;
    },
    _rotateAround(angle, p) {
        const cos = Math.cos(angle), sin = Math.sin(angle), x = p.x + cos * (this.x - p.x) - sin * (this.y - p.y), y = p.y + sin * (this.x - p.x) + cos * (this.y - p.y);
        this.x = x;
        this.y = y;
        return this;
    },
    _round() {
        this.x = Math.round(this.x);
        this.y = Math.round(this.y);
        return this;
    },
    constructor: Point
};
Point.convert = function (p) {
    if (p instanceof Point) {
        return (p);
    }
    if (Array.isArray(p)) {
        return new Point(+p[0], +p[1]);
    }
    if (p.x !== undefined && p.y !== undefined) {
        return new Point(+p.x, +p.y);
    }
    throw new Error('Expected [x, y] or {x, y} point format');
};

},
/* basemaps/styles/src/index.ts */
11: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.BLACK = exports.GRAYSCALE = exports.WHITE = exports.DARK = exports.LIGHT = exports.language_script_pairs = void 0;
exports.namedFlavor = namedFlavor;
exports.layers = layers;
const base_layers_1 = __req(21);
const flavors_1 = __req(22);
Object.defineProperty(exports, "BLACK", { enumerable: true, get: function () { return flavors_1.BLACK; } });
Object.defineProperty(exports, "DARK", { enumerable: true, get: function () { return flavors_1.DARK; } });
Object.defineProperty(exports, "GRAYSCALE", { enumerable: true, get: function () { return flavors_1.GRAYSCALE; } });
Object.defineProperty(exports, "LIGHT", { enumerable: true, get: function () { return flavors_1.LIGHT; } });
Object.defineProperty(exports, "WHITE", { enumerable: true, get: function () { return flavors_1.WHITE; } });
const language_1 = __req(23);
Object.defineProperty(exports, "language_script_pairs", { enumerable: true, get: function () { return language_1.language_script_pairs; } });
function namedFlavor(name) {
    switch (name) {
        case "light":
            return flavors_1.LIGHT;
        case "dark":
            return flavors_1.DARK;
        case "white":
            return flavors_1.WHITE;
        case "grayscale":
            return flavors_1.GRAYSCALE;
        case "black":
            return flavors_1.BLACK;
    }
    throw new Error("Flavor not found");
}
function layers(source, flavor, options) {
    let layers = [];
    if (!(options === null || options === void 0 ? void 0 : options.labelsOnly)) {
        layers = (0, base_layers_1.nolabels_layers)(source, flavor);
    }
    if (options === null || options === void 0 ? void 0 : options.lang) {
        layers = layers.concat((0, base_layers_1.labels_layers)(source, flavor, options.lang));
    }
    return layers;
}

},
/* protomaps-leaflet/src/attribute.ts */
12: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.ArrayAttr = exports.FontAttr = exports.TextAttr = exports.NumberAttr = exports.StringAttr = void 0;
class StringAttr {
    constructor(c, defaultValue) {
        this.str = c !== null && c !== void 0 ? c : defaultValue;
        this.perFeature = typeof this.str === "function" && this.str.length === 2;
    }
    get(z, f) {
        if (typeof this.str === "function") {
            return this.str(z, f);
        }
        return this.str;
    }
}
exports.StringAttr = StringAttr;
class NumberAttr {
    constructor(c, defaultValue = 1) {
        this.value = c !== null && c !== void 0 ? c : defaultValue;
        this.perFeature =
            typeof this.value === "function" && this.value.length === 2;
    }
    get(z, f) {
        if (typeof this.value === "function") {
            return this.value(z, f);
        }
        return this.value;
    }
}
exports.NumberAttr = NumberAttr;
class TextAttr {
    constructor(options) {
        var _a;
        this.labelProps = (_a = options === null || options === void 0 ? void 0 : options.labelProps) !== null && _a !== void 0 ? _a : ["name"];
        this.textTransform = options === null || options === void 0 ? void 0 : options.textTransform;
    }
    get(z, f) {
        let retval;
        let labelProps;
        if (typeof this.labelProps === "function") {
            labelProps = this.labelProps(z, f);
        }
        else {
            labelProps = this.labelProps;
        }
        for (const property of labelProps) {
            if (Object.prototype.hasOwnProperty.call(f.props, property) &&
                typeof f.props[property] === "string") {
                retval = f.props[property];
                break;
            }
        }
        let transform;
        if (typeof this.textTransform === "function") {
            transform = this.textTransform(z, f);
        }
        else {
            transform = this.textTransform;
        }
        if (retval && transform === "uppercase")
            retval = retval.toUpperCase();
        else if (retval && transform === "lowercase")
            retval = retval.toLowerCase();
        else if (retval && transform === "capitalize") {
            const wordsArray = retval.toLowerCase().split(" ");
            const capsArray = wordsArray.map((word) => {
                return word[0].toUpperCase() + word.slice(1);
            });
            retval = capsArray.join(" ");
        }
        return retval;
    }
}
exports.TextAttr = TextAttr;
class FontAttr {
    constructor(options) {
        var _a, _b;
        if (options === null || options === void 0 ? void 0 : options.font) {
            this.font = options.font;
        }
        else {
            this.family = (_a = options === null || options === void 0 ? void 0 : options.fontFamily) !== null && _a !== void 0 ? _a : "sans-serif";
            this.size = (_b = options === null || options === void 0 ? void 0 : options.fontSize) !== null && _b !== void 0 ? _b : 12;
            this.weight = options === null || options === void 0 ? void 0 : options.fontWeight;
            this.style = options === null || options === void 0 ? void 0 : options.fontStyle;
        }
    }
    get(z, f) {
        if (this.font) {
            if (typeof this.font === "function") {
                return this.font(z, f);
            }
            return this.font;
        }
        let style = "";
        if (this.style) {
            if (typeof this.style === "function") {
                style = `${this.style(z, f)} `;
            }
            else {
                style = `${this.style} `;
            }
        }
        let weight = "";
        if (this.weight) {
            if (typeof this.weight === "function") {
                weight = `${this.weight(z, f)} `;
            }
            else {
                weight = `${this.weight} `;
            }
        }
        let size;
        if (typeof this.size === "function") {
            size = this.size(z, f);
        }
        else {
            size = this.size;
        }
        let family;
        if (typeof this.family === "function") {
            family = this.family(z, f);
        }
        else {
            family = this.family;
        }
        return `${style}${weight}${size}px ${family}`;
    }
}
exports.FontAttr = FontAttr;
class ArrayAttr {
    constructor(c, defaultValue = []) {
        this.value = c !== null && c !== void 0 ? c : defaultValue;
        this.perFeature =
            typeof this.value === "function" && this.value.length === 2;
    }
    get(z, f) {
        if (typeof this.value === "function") {
            return this.value(z, f);
        }
        return this.value;
    }
}
exports.ArrayAttr = ArrayAttr;

},
/* protomaps-leaflet/src/line.ts */
13: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.simpleLabel = simpleLabel;
exports.lineCells = lineCells;
const point_geometry_1 = __importDefault(__req(10));
const linelabel = (pts, maxAngleDelta, targetLen) => {
    const chunks = [];
    let a;
    let b;
    let c;
    let i = 0;
    let n = 0;
    let d = 0;
    let abmag = 0;
    let bcmag = 0;
    let abx = 0;
    let aby = 0;
    let bcx = 0;
    let bcy = 0;
    let dt = 0;
    let iStart = 0;
    let dStart = 0;
    if (pts.length < 2)
        return [];
    if (pts.length === 2) {
        d = Math.sqrt((pts[1].x - pts[0].x) ** 2 + (pts[1].y - pts[0].y) ** 2);
        return [
            {
                length: d,
                beginIndex: 0,
                beginDistance: 0,
                endIndex: 2,
                endDistance: d,
            },
        ];
    }
    abmag = Math.sqrt((pts[1].x - pts[0].x) ** 2 + (pts[1].y - pts[0].y) ** 2);
    for (i = 1, n = pts.length - 1; i < n; i++) {
        a = pts[i - 1];
        b = pts[i];
        c = pts[i + 1];
        abx = b.x - a.x;
        aby = b.y - a.y;
        bcx = c.x - b.x;
        bcy = c.y - b.y;
        bcmag = Math.sqrt(bcx * bcx + bcy * bcy);
        d += abmag;
        dt = Math.acos((abx * bcx + aby * bcy) / (abmag * bcmag));
        if (dt > maxAngleDelta || d - dStart > targetLen) {
            chunks.push({
                length: d - dStart,
                beginDistance: dStart,
                beginIndex: iStart,
                endIndex: i + 1,
                endDistance: d,
            });
            iStart = i;
            dStart = d;
        }
        abmag = bcmag;
    }
    if (i - iStart > 0) {
        chunks.push({
            length: d - dStart + bcmag,
            beginIndex: iStart,
            beginDistance: dStart,
            endIndex: i + 1,
            endDistance: d + bcmag,
        });
    }
    return chunks;
};
function simpleLabel(mls, minimum, repeatDistance, cellSize) {
    const candidates = [];
    for (const ls of mls) {
        const segments = linelabel(ls, Math.PI / 45, minimum);
        for (const segment of segments) {
            if (segment.length >= minimum + cellSize) {
                const start = new point_geometry_1.default(ls[segment.beginIndex].x, ls[segment.beginIndex].y);
                const end = ls[segment.endIndex - 1];
                const normalized = new point_geometry_1.default((end.x - start.x) / segment.length, (end.y - start.y) / segment.length);
                for (let i = cellSize; i < segment.length - minimum; i += repeatDistance) {
                    candidates.push({
                        start: start.add(normalized.mult(i)),
                        end: start.add(normalized.mult(i + minimum)),
                    });
                }
            }
        }
    }
    return candidates;
}
function lineCells(a, b, length, spacing) {
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const dist = Math.sqrt((b.x - a.x) ** 2 + (b.y - a.y) ** 2);
    const retval = [];
    for (let i = 0; i < length + spacing; i += 2 * spacing) {
        const factor = (i * 1) / dist;
        retval.push({ x: a.x + factor * dx, y: a.y + factor * dy });
    }
    return retval;
}

},
/* protomaps-leaflet/src/text.ts */
14: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.linebreak = linebreak;
exports.isCjk = isCjk;
function linebreak(str, maxUnits) {
    if (str.length <= maxUnits)
        return [str];
    const endIndex = maxUnits - 1;
    const spaceBefore = str.lastIndexOf(" ", endIndex);
    const spaceAfter = str.indexOf(" ", endIndex);
    if (spaceBefore === -1 && spaceAfter === -1) {
        return [str];
    }
    let first;
    let after;
    if (spaceAfter === -1 ||
        (spaceBefore >= 0 && endIndex - spaceBefore < spaceAfter - endIndex)) {
        first = str.substring(0, spaceBefore);
        after = str.substring(spaceBefore + 1, str.length);
    }
    else {
        first = str.substring(0, spaceAfter);
        after = str.substring(spaceAfter + 1, str.length);
    }
    return [first, ...linebreak(after, maxUnits)];
}
const CJK_CHARS = "\u3005-\u3007\u3021-\u3029\u3031-\u3035\u3038-\u303C\u3220-\u3229\u3248-\u324F\u3251-\u325F\u3280-\u3289\u32B1-\u32BF\u3400-\u4DB5\u4E00-\u9FEA\uF900-\uFA6D\uFA70-\uFAD9\u2000";
const cjkTest = new RegExp(`^[${CJK_CHARS}]+$`);
function isCjk(s) {
    return cjkTest.test(s);
}

},
/* potpack/index.mjs */
15: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.default = potpack;
function potpack(boxes) {
    let area = 0;
    let maxWidth = 0;
    for (const box of boxes) {
        area += box.w * box.h;
        maxWidth = Math.max(maxWidth, box.w);
    }
    boxes.sort((a, b) => b.h - a.h);
    const startWidth = Math.max(Math.ceil(Math.sqrt(area / 0.95)), maxWidth);
    const spaces = [{ x: 0, y: 0, w: startWidth, h: Infinity }];
    let width = 0;
    let height = 0;
    for (const box of boxes) {
        for (let i = spaces.length - 1; i >= 0; i--) {
            const space = spaces[i];
            if (box.w > space.w || box.h > space.h)
                continue;
            box.x = space.x;
            box.y = space.y;
            height = Math.max(height, box.y + box.h);
            width = Math.max(width, box.x + box.w);
            if (box.w === space.w && box.h === space.h) {
                const last = spaces.pop();
                if (i < spaces.length)
                    spaces[i] = last;
            }
            else if (box.h === space.h) {
                space.x += box.w;
                space.w -= box.w;
            }
            else if (box.w === space.w) {
                space.y += box.h;
                space.h -= box.h;
            }
            else {
                spaces.push({
                    x: space.x + box.w,
                    y: space.y,
                    w: space.w - box.w,
                    h: box.h
                });
                space.y += box.h;
                space.h -= box.h;
            }
            break;
        }
    }
    return {
        w: width,
        h: height,
        fill: (area / (width * height)) || 0
    };
}

},
/* color2k/src/index.ts */
16: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.toHsla = exports.toRgba = exports.toHex = exports.ColorError = exports.parseToRgba = exports.transparentize = exports.saturate = exports.rgba = exports.readableColorIsBlack = exports.readableColor = exports.parseToHsla = exports.opacify = exports.mix = exports.lighten = exports.hsla = exports.hasBadContrast = exports.guard = exports.getScale = exports.getLuminance = exports.getContrast = exports.desaturate = exports.darken = exports.adjustHue = void 0;
var adjustHue_1 = __req(24);
Object.defineProperty(exports, "adjustHue", { enumerable: true, get: function () { return __importDefault(adjustHue_1).default; } });
var darken_1 = __req(25);
Object.defineProperty(exports, "darken", { enumerable: true, get: function () { return __importDefault(darken_1).default; } });
var desaturate_1 = __req(26);
Object.defineProperty(exports, "desaturate", { enumerable: true, get: function () { return __importDefault(desaturate_1).default; } });
var getContrast_1 = __req(27);
Object.defineProperty(exports, "getContrast", { enumerable: true, get: function () { return __importDefault(getContrast_1).default; } });
var getLuminance_1 = __req(28);
Object.defineProperty(exports, "getLuminance", { enumerable: true, get: function () { return __importDefault(getLuminance_1).default; } });
var getScale_1 = __req(29);
Object.defineProperty(exports, "getScale", { enumerable: true, get: function () { return __importDefault(getScale_1).default; } });
var guard_1 = __req(30);
Object.defineProperty(exports, "guard", { enumerable: true, get: function () { return __importDefault(guard_1).default; } });
var hasBadContrast_1 = __req(31);
Object.defineProperty(exports, "hasBadContrast", { enumerable: true, get: function () { return __importDefault(hasBadContrast_1).default; } });
var hsla_1 = __req(32);
Object.defineProperty(exports, "hsla", { enumerable: true, get: function () { return __importDefault(hsla_1).default; } });
var lighten_1 = __req(33);
Object.defineProperty(exports, "lighten", { enumerable: true, get: function () { return __importDefault(lighten_1).default; } });
var mix_1 = __req(34);
Object.defineProperty(exports, "mix", { enumerable: true, get: function () { return __importDefault(mix_1).default; } });
var opacify_1 = __req(35);
Object.defineProperty(exports, "opacify", { enumerable: true, get: function () { return __importDefault(opacify_1).default; } });
var parseToHsla_1 = __req(36);
Object.defineProperty(exports, "parseToHsla", { enumerable: true, get: function () { return __importDefault(parseToHsla_1).default; } });
var readableColor_1 = __req(37);
Object.defineProperty(exports, "readableColor", { enumerable: true, get: function () { return __importDefault(readableColor_1).default; } });
var readableColorIsBlack_1 = __req(38);
Object.defineProperty(exports, "readableColorIsBlack", { enumerable: true, get: function () { return __importDefault(readableColorIsBlack_1).default; } });
var rgba_1 = __req(39);
Object.defineProperty(exports, "rgba", { enumerable: true, get: function () { return __importDefault(rgba_1).default; } });
var saturate_1 = __req(40);
Object.defineProperty(exports, "saturate", { enumerable: true, get: function () { return __importDefault(saturate_1).default; } });
var transparentize_1 = __req(41);
Object.defineProperty(exports, "transparentize", { enumerable: true, get: function () { return __importDefault(transparentize_1).default; } });
var parseToRgba_1 = __req(42);
Object.defineProperty(exports, "parseToRgba", { enumerable: true, get: function () { return __importDefault(parseToRgba_1).default; } });
var ColorError_1 = __req(43);
Object.defineProperty(exports, "ColorError", { enumerable: true, get: function () { return __importDefault(ColorError_1).default; } });
var toHex_1 = __req(44);
Object.defineProperty(exports, "toHex", { enumerable: true, get: function () { return __importDefault(toHex_1).default; } });
var toRgba_1 = __req(45);
Object.defineProperty(exports, "toRgba", { enumerable: true, get: function () { return __importDefault(toRgba_1).default; } });
var toHsla_1 = __req(46);
Object.defineProperty(exports, "toHsla", { enumerable: true, get: function () { return __importDefault(toHsla_1).default; } });

},
/* vector-tile/index.js */
17: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.VectorTile = exports.VectorTileLayer = exports.VectorTileFeature = void 0;
exports.classifyRings = classifyRings;
const point_geometry_1 = __importDefault(__req(10));
class VectorTileFeature {
    constructor(pbf, end, extent, keys, values) {
        this.properties = {};
        this.extent = extent;
        this.type = 0;
        this.id = undefined;
        this._pbf = pbf;
        this._geometry = -1;
        this._keys = keys;
        this._values = values;
        pbf.readFields(readFeature, this, end);
    }
    loadGeometry() {
        const pbf = this._pbf;
        pbf.pos = this._geometry;
        const end = pbf.readVarint() + pbf.pos;
        const lines = [];
        let line;
        let cmd = 1;
        let length = 0;
        let x = 0;
        let y = 0;
        while (pbf.pos < end) {
            if (length <= 0) {
                const cmdLen = pbf.readVarint();
                cmd = cmdLen & 0x7;
                length = cmdLen >> 3;
            }
            length--;
            if (cmd === 1 || cmd === 2) {
                x += pbf.readSVarint();
                y += pbf.readSVarint();
                if (cmd === 1) {
                    if (line)
                        lines.push(line);
                    line = [];
                }
                if (line)
                    line.push(new point_geometry_1.default(x, y));
            }
            else if (cmd === 7) {
                if (line) {
                    line.push(line[0].clone());
                }
            }
            else {
                throw new Error(`unknown command ${cmd}`);
            }
        }
        if (line)
            lines.push(line);
        return lines;
    }
    bbox() {
        const pbf = this._pbf;
        pbf.pos = this._geometry;
        const end = pbf.readVarint() + pbf.pos;
        let cmd = 1, length = 0, x = 0, y = 0, x1 = Infinity, x2 = -Infinity, y1 = Infinity, y2 = -Infinity;
        while (pbf.pos < end) {
            if (length <= 0) {
                const cmdLen = pbf.readVarint();
                cmd = cmdLen & 0x7;
                length = cmdLen >> 3;
            }
            length--;
            if (cmd === 1 || cmd === 2) {
                x += pbf.readSVarint();
                y += pbf.readSVarint();
                if (x < x1)
                    x1 = x;
                if (x > x2)
                    x2 = x;
                if (y < y1)
                    y1 = y;
                if (y > y2)
                    y2 = y;
            }
            else if (cmd !== 7) {
                throw new Error(`unknown command ${cmd}`);
            }
        }
        return [x1, y1, x2, y2];
    }
    toGeoJSON(x, y, z) {
        const size = this.extent * Math.pow(2, z), x0 = this.extent * x, y0 = this.extent * y, vtCoords = this.loadGeometry();
        function projectPoint(p) {
            return [
                (p.x + x0) * 360 / size - 180,
                360 / Math.PI * Math.atan(Math.exp((1 - (p.y + y0) * 2 / size) * Math.PI)) - 90
            ];
        }
        function projectLine(line) {
            return line.map(projectPoint);
        }
        let geometry;
        if (this.type === 1) {
            const points = [];
            for (const line of vtCoords) {
                points.push(line[0]);
            }
            const coordinates = projectLine(points);
            geometry = points.length === 1 ?
                { type: 'Point', coordinates: coordinates[0] } :
                { type: 'MultiPoint', coordinates };
        }
        else if (this.type === 2) {
            const coordinates = vtCoords.map(projectLine);
            geometry = coordinates.length === 1 ?
                { type: 'LineString', coordinates: coordinates[0] } :
                { type: 'MultiLineString', coordinates };
        }
        else if (this.type === 3) {
            const polygons = classifyRings(vtCoords);
            const coordinates = [];
            for (const polygon of polygons) {
                coordinates.push(polygon.map(projectLine));
            }
            geometry = coordinates.length === 1 ?
                { type: 'Polygon', coordinates: coordinates[0] } :
                { type: 'MultiPolygon', coordinates };
        }
        else {
            throw new Error('unknown feature type');
        }
        const result = {
            type: 'Feature',
            geometry,
            properties: this.properties
        };
        if (this.id != null) {
            result.id = this.id;
        }
        return result;
    }
}
exports.VectorTileFeature = VectorTileFeature;
VectorTileFeature.types = ['Unknown', 'Point', 'LineString', 'Polygon'];
function readFeature(tag, feature, pbf) {
    if (tag === 1)
        feature.id = pbf.readVarint();
    else if (tag === 2)
        readTag(pbf, feature);
    else if (tag === 3)
        feature.type = (pbf.readVarint());
    else if (tag === 4)
        feature._geometry = pbf.pos;
}
function readTag(pbf, feature) {
    const end = pbf.readVarint() + pbf.pos;
    while (pbf.pos < end) {
        const key = feature._keys[pbf.readVarint()], value = feature._values[pbf.readVarint()];
        feature.properties[key] = value;
    }
}
function classifyRings(rings) {
    const len = rings.length;
    if (len <= 1)
        return [rings];
    const polygons = [];
    let polygon, ccw;
    for (let i = 0; i < len; i++) {
        const area = signedArea(rings[i]);
        if (area === 0)
            continue;
        if (ccw === undefined)
            ccw = area < 0;
        if (ccw === area < 0) {
            if (polygon)
                polygons.push(polygon);
            polygon = [rings[i]];
        }
        else if (polygon) {
            polygon.push(rings[i]);
        }
    }
    if (polygon)
        polygons.push(polygon);
    return polygons;
}
function signedArea(ring) {
    let sum = 0;
    for (let i = 0, len = ring.length, j = len - 1, p1, p2; i < len; j = i++) {
        p1 = ring[i];
        p2 = ring[j];
        sum += (p2.x - p1.x) * (p1.y + p2.y);
    }
    return sum;
}
class VectorTileLayer {
    constructor(pbf, end) {
        this.version = 1;
        this.name = '';
        this.extent = 4096;
        this.length = 0;
        this._pbf = pbf;
        this._keys = [];
        this._values = [];
        this._features = [];
        pbf.readFields(readLayer, this, end);
        this.length = this._features.length;
    }
    feature(i) {
        if (i < 0 || i >= this._features.length)
            throw new Error('feature index out of bounds');
        this._pbf.pos = this._features[i];
        const end = this._pbf.readVarint() + this._pbf.pos;
        return new VectorTileFeature(this._pbf, end, this.extent, this._keys, this._values);
    }
}
exports.VectorTileLayer = VectorTileLayer;
function readLayer(tag, layer, pbf) {
    if (tag === 15)
        layer.version = pbf.readVarint();
    else if (tag === 1)
        layer.name = pbf.readString();
    else if (tag === 5)
        layer.extent = pbf.readVarint();
    else if (tag === 2)
        layer._features.push(pbf.pos);
    else if (tag === 3)
        layer._keys.push(pbf.readString());
    else if (tag === 4)
        layer._values.push(readValueMessage(pbf));
}
function readValueMessage(pbf) {
    let value = null;
    const end = pbf.readVarint() + pbf.pos;
    while (pbf.pos < end) {
        const tag = pbf.readVarint() >> 3;
        value = tag === 1 ? pbf.readString() :
            tag === 2 ? pbf.readFloat() :
                tag === 3 ? pbf.readDouble() :
                    tag === 4 ? pbf.readVarint64() :
                        tag === 5 ? pbf.readVarint() :
                            tag === 6 ? pbf.readSVarint() :
                                tag === 7 ? pbf.readBoolean() : null;
    }
    return value;
}
class VectorTile {
    constructor(pbf, end) {
        this.layers = pbf.readFields(readTile, {}, end);
    }
}
exports.VectorTile = VectorTile;
function readTile(tag, layers, pbf) {
    if (tag === 3) {
        const layer = new VectorTileLayer(pbf, pbf.readVarint() + pbf.pos);
        if (layer.length)
            layers[layer.name] = layer;
    }
}

},
/* pbf/index.js */
18: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const SHIFT_LEFT_32 = (1 << 16) * (1 << 16);
const SHIFT_RIGHT_32 = 1 / SHIFT_LEFT_32;
const TEXT_DECODER_MIN_LENGTH = 12;
const utf8TextDecoder = typeof TextDecoder === 'undefined' ? null : new TextDecoder('utf-8');
const PBF_VARINT = 0;
const PBF_FIXED64 = 1;
const PBF_BYTES = 2;
const PBF_FIXED32 = 5;
class Pbf {
    constructor(buf = new Uint8Array(16)) {
        this.buf = ArrayBuffer.isView(buf) ? buf : new Uint8Array(buf);
        this.dataView = new DataView(this.buf.buffer);
        this.pos = 0;
        this.type = 0;
        this.length = this.buf.length;
    }
    readFields(readField, result, end = this.length) {
        while (this.pos < end) {
            const val = this.readVarint(), tag = val >> 3, startPos = this.pos;
            this.type = val & 0x7;
            readField(tag, result, this);
            if (this.pos === startPos)
                this.skip(val);
        }
        return result;
    }
    readMessage(readField, result) {
        return this.readFields(readField, result, this.readVarint() + this.pos);
    }
    readFixed32() {
        const val = this.dataView.getUint32(this.pos, true);
        this.pos += 4;
        return val;
    }
    readSFixed32() {
        const val = this.dataView.getInt32(this.pos, true);
        this.pos += 4;
        return val;
    }
    readFixed64() {
        const val = this.dataView.getUint32(this.pos, true) + this.dataView.getUint32(this.pos + 4, true) * SHIFT_LEFT_32;
        this.pos += 8;
        return val;
    }
    readSFixed64() {
        const val = this.dataView.getUint32(this.pos, true) + this.dataView.getInt32(this.pos + 4, true) * SHIFT_LEFT_32;
        this.pos += 8;
        return val;
    }
    readFloat() {
        const val = this.dataView.getFloat32(this.pos, true);
        this.pos += 4;
        return val;
    }
    readDouble() {
        const val = this.dataView.getFloat64(this.pos, true);
        this.pos += 8;
        return val;
    }
    readVarint(isSigned) {
        const buf = this.buf;
        let val, b;
        b = buf[this.pos++];
        val = b & 0x7f;
        if (b < 0x80)
            return val;
        b = buf[this.pos++];
        val |= (b & 0x7f) << 7;
        if (b < 0x80)
            return val;
        b = buf[this.pos++];
        val |= (b & 0x7f) << 14;
        if (b < 0x80)
            return val;
        b = buf[this.pos++];
        val |= (b & 0x7f) << 21;
        if (b < 0x80)
            return val;
        b = buf[this.pos];
        val |= (b & 0x0f) << 28;
        return readVarintRemainder(val, isSigned, this);
    }
    readVarint64() {
        return this.readVarint(true);
    }
    readSVarint() {
        const num = this.readVarint();
        return num % 2 === 1 ? (num + 1) / -2 : num / 2;
    }
    readBoolean() {
        return Boolean(this.readVarint());
    }
    readString() {
        const end = this.readVarint() + this.pos;
        const pos = this.pos;
        this.pos = end;
        if (end - pos >= TEXT_DECODER_MIN_LENGTH && utf8TextDecoder) {
            return utf8TextDecoder.decode(this.buf.subarray(pos, end));
        }
        return readUtf8(this.buf, pos, end);
    }
    readBytes() {
        const end = this.readVarint() + this.pos, buffer = this.buf.subarray(this.pos, end);
        this.pos = end;
        return buffer;
    }
    readPackedVarint(arr = [], isSigned) {
        const end = this.readPackedEnd();
        while (this.pos < end)
            arr.push(this.readVarint(isSigned));
        return arr;
    }
    readPackedSVarint(arr = []) {
        const end = this.readPackedEnd();
        while (this.pos < end)
            arr.push(this.readSVarint());
        return arr;
    }
    readPackedBoolean(arr = []) {
        const end = this.readPackedEnd();
        while (this.pos < end)
            arr.push(this.readBoolean());
        return arr;
    }
    readPackedFloat(arr = []) {
        const end = this.readPackedEnd();
        while (this.pos < end)
            arr.push(this.readFloat());
        return arr;
    }
    readPackedDouble(arr = []) {
        const end = this.readPackedEnd();
        while (this.pos < end)
            arr.push(this.readDouble());
        return arr;
    }
    readPackedFixed32(arr = []) {
        const end = this.readPackedEnd();
        while (this.pos < end)
            arr.push(this.readFixed32());
        return arr;
    }
    readPackedSFixed32(arr = []) {
        const end = this.readPackedEnd();
        while (this.pos < end)
            arr.push(this.readSFixed32());
        return arr;
    }
    readPackedFixed64(arr = []) {
        const end = this.readPackedEnd();
        while (this.pos < end)
            arr.push(this.readFixed64());
        return arr;
    }
    readPackedSFixed64(arr = []) {
        const end = this.readPackedEnd();
        while (this.pos < end)
            arr.push(this.readSFixed64());
        return arr;
    }
    readPackedEnd() {
        return this.type === PBF_BYTES ? this.readVarint() + this.pos : this.pos + 1;
    }
    skip(val) {
        const type = val & 0x7;
        if (type === PBF_VARINT)
            while (this.buf[this.pos++] > 0x7f) { }
        else if (type === PBF_BYTES)
            this.pos = this.readVarint() + this.pos;
        else if (type === PBF_FIXED32)
            this.pos += 4;
        else if (type === PBF_FIXED64)
            this.pos += 8;
        else
            throw new Error(`Unimplemented type: ${type}`);
    }
    writeTag(tag, type) {
        this.writeVarint((tag << 3) | type);
    }
    realloc(min) {
        let length = this.length || 16;
        while (length < this.pos + min)
            length *= 2;
        if (length !== this.length) {
            const buf = new Uint8Array(length);
            buf.set(this.buf);
            this.buf = buf;
            this.dataView = new DataView(buf.buffer);
            this.length = length;
        }
    }
    finish() {
        this.length = this.pos;
        this.pos = 0;
        return this.buf.subarray(0, this.length);
    }
    writeFixed32(val) {
        this.realloc(4);
        this.dataView.setInt32(this.pos, val, true);
        this.pos += 4;
    }
    writeSFixed32(val) {
        this.realloc(4);
        this.dataView.setInt32(this.pos, val, true);
        this.pos += 4;
    }
    writeFixed64(val) {
        this.realloc(8);
        this.dataView.setInt32(this.pos, val & -1, true);
        this.dataView.setInt32(this.pos + 4, Math.floor(val * SHIFT_RIGHT_32), true);
        this.pos += 8;
    }
    writeSFixed64(val) {
        this.realloc(8);
        this.dataView.setInt32(this.pos, val & -1, true);
        this.dataView.setInt32(this.pos + 4, Math.floor(val * SHIFT_RIGHT_32), true);
        this.pos += 8;
    }
    writeVarint(val) {
        val = +val || 0;
        if (val > 0xfffffff || val < 0) {
            writeBigVarint(val, this);
            return;
        }
        this.realloc(4);
        this.buf[this.pos++] = val & 0x7f | (val > 0x7f ? 0x80 : 0);
        if (val <= 0x7f)
            return;
        this.buf[this.pos++] = ((val >>>= 7) & 0x7f) | (val > 0x7f ? 0x80 : 0);
        if (val <= 0x7f)
            return;
        this.buf[this.pos++] = ((val >>>= 7) & 0x7f) | (val > 0x7f ? 0x80 : 0);
        if (val <= 0x7f)
            return;
        this.buf[this.pos++] = (val >>> 7) & 0x7f;
    }
    writeSVarint(val) {
        this.writeVarint(val < 0 ? -val * 2 - 1 : val * 2);
    }
    writeBoolean(val) {
        this.writeVarint(+val);
    }
    writeString(str) {
        str = String(str);
        this.realloc(str.length * 4);
        this.pos++;
        const startPos = this.pos;
        this.pos = writeUtf8(this.buf, str, this.pos);
        const len = this.pos - startPos;
        if (len >= 0x80)
            makeRoomForExtraLength(startPos, len, this);
        this.pos = startPos - 1;
        this.writeVarint(len);
        this.pos += len;
    }
    writeFloat(val) {
        this.realloc(4);
        this.dataView.setFloat32(this.pos, val, true);
        this.pos += 4;
    }
    writeDouble(val) {
        this.realloc(8);
        this.dataView.setFloat64(this.pos, val, true);
        this.pos += 8;
    }
    writeBytes(buffer) {
        const len = buffer.length;
        this.writeVarint(len);
        this.realloc(len);
        for (let i = 0; i < len; i++)
            this.buf[this.pos++] = buffer[i];
    }
    writeRawMessage(fn, obj) {
        this.pos++;
        const startPos = this.pos;
        fn(obj, this);
        const len = this.pos - startPos;
        if (len >= 0x80)
            makeRoomForExtraLength(startPos, len, this);
        this.pos = startPos - 1;
        this.writeVarint(len);
        this.pos += len;
    }
    writeMessage(tag, fn, obj) {
        this.writeTag(tag, PBF_BYTES);
        this.writeRawMessage(fn, obj);
    }
    writePackedVarint(tag, arr) {
        if (arr.length)
            this.writeMessage(tag, writePackedVarint, arr);
    }
    writePackedSVarint(tag, arr) {
        if (arr.length)
            this.writeMessage(tag, writePackedSVarint, arr);
    }
    writePackedBoolean(tag, arr) {
        if (arr.length)
            this.writeMessage(tag, writePackedBoolean, arr);
    }
    writePackedFloat(tag, arr) {
        if (arr.length)
            this.writeMessage(tag, writePackedFloat, arr);
    }
    writePackedDouble(tag, arr) {
        if (arr.length)
            this.writeMessage(tag, writePackedDouble, arr);
    }
    writePackedFixed32(tag, arr) {
        if (arr.length)
            this.writeMessage(tag, writePackedFixed32, arr);
    }
    writePackedSFixed32(tag, arr) {
        if (arr.length)
            this.writeMessage(tag, writePackedSFixed32, arr);
    }
    writePackedFixed64(tag, arr) {
        if (arr.length)
            this.writeMessage(tag, writePackedFixed64, arr);
    }
    writePackedSFixed64(tag, arr) {
        if (arr.length)
            this.writeMessage(tag, writePackedSFixed64, arr);
    }
    writeBytesField(tag, buffer) {
        this.writeTag(tag, PBF_BYTES);
        this.writeBytes(buffer);
    }
    writeFixed32Field(tag, val) {
        this.writeTag(tag, PBF_FIXED32);
        this.writeFixed32(val);
    }
    writeSFixed32Field(tag, val) {
        this.writeTag(tag, PBF_FIXED32);
        this.writeSFixed32(val);
    }
    writeFixed64Field(tag, val) {
        this.writeTag(tag, PBF_FIXED64);
        this.writeFixed64(val);
    }
    writeSFixed64Field(tag, val) {
        this.writeTag(tag, PBF_FIXED64);
        this.writeSFixed64(val);
    }
    writeVarintField(tag, val) {
        this.writeTag(tag, PBF_VARINT);
        this.writeVarint(val);
    }
    writeSVarintField(tag, val) {
        this.writeTag(tag, PBF_VARINT);
        this.writeSVarint(val);
    }
    writeStringField(tag, str) {
        this.writeTag(tag, PBF_BYTES);
        this.writeString(str);
    }
    writeFloatField(tag, val) {
        this.writeTag(tag, PBF_FIXED32);
        this.writeFloat(val);
    }
    writeDoubleField(tag, val) {
        this.writeTag(tag, PBF_FIXED64);
        this.writeDouble(val);
    }
    writeBooleanField(tag, val) {
        this.writeVarintField(tag, +val);
    }
}
exports.default = Pbf;
;
function readVarintRemainder(l, s, p) {
    const buf = p.buf;
    let h, b;
    b = buf[p.pos++];
    h = (b & 0x70) >> 4;
    if (b < 0x80)
        return toNum(l, h, s);
    b = buf[p.pos++];
    h |= (b & 0x7f) << 3;
    if (b < 0x80)
        return toNum(l, h, s);
    b = buf[p.pos++];
    h |= (b & 0x7f) << 10;
    if (b < 0x80)
        return toNum(l, h, s);
    b = buf[p.pos++];
    h |= (b & 0x7f) << 17;
    if (b < 0x80)
        return toNum(l, h, s);
    b = buf[p.pos++];
    h |= (b & 0x7f) << 24;
    if (b < 0x80)
        return toNum(l, h, s);
    b = buf[p.pos++];
    h |= (b & 0x01) << 31;
    if (b < 0x80)
        return toNum(l, h, s);
    throw new Error('Expected varint not more than 10 bytes');
}
function toNum(low, high, isSigned) {
    return isSigned ? high * 0x100000000 + (low >>> 0) : ((high >>> 0) * 0x100000000) + (low >>> 0);
}
function writeBigVarint(val, pbf) {
    let low, high;
    if (val >= 0) {
        low = (val % 0x100000000) | 0;
        high = (val / 0x100000000) | 0;
    }
    else {
        low = ~(-val % 0x100000000);
        high = ~(-val / 0x100000000);
        if (low ^ 0xffffffff) {
            low = (low + 1) | 0;
        }
        else {
            low = 0;
            high = (high + 1) | 0;
        }
    }
    if (val >= 0x10000000000000000 || val < -0x10000000000000000) {
        throw new Error('Given varint doesn\'t fit into 10 bytes');
    }
    pbf.realloc(10);
    writeBigVarintLow(low, high, pbf);
    writeBigVarintHigh(high, pbf);
}
function writeBigVarintLow(low, high, pbf) {
    pbf.buf[pbf.pos++] = low & 0x7f | 0x80;
    low >>>= 7;
    pbf.buf[pbf.pos++] = low & 0x7f | 0x80;
    low >>>= 7;
    pbf.buf[pbf.pos++] = low & 0x7f | 0x80;
    low >>>= 7;
    pbf.buf[pbf.pos++] = low & 0x7f | 0x80;
    low >>>= 7;
    pbf.buf[pbf.pos] = low & 0x7f;
}
function writeBigVarintHigh(high, pbf) {
    const lsb = (high & 0x07) << 4;
    pbf.buf[pbf.pos++] |= lsb | ((high >>>= 3) ? 0x80 : 0);
    if (!high)
        return;
    pbf.buf[pbf.pos++] = high & 0x7f | ((high >>>= 7) ? 0x80 : 0);
    if (!high)
        return;
    pbf.buf[pbf.pos++] = high & 0x7f | ((high >>>= 7) ? 0x80 : 0);
    if (!high)
        return;
    pbf.buf[pbf.pos++] = high & 0x7f | ((high >>>= 7) ? 0x80 : 0);
    if (!high)
        return;
    pbf.buf[pbf.pos++] = high & 0x7f | ((high >>>= 7) ? 0x80 : 0);
    if (!high)
        return;
    pbf.buf[pbf.pos++] = high & 0x7f;
}
function makeRoomForExtraLength(startPos, len, pbf) {
    const extraLen = len <= 0x3fff ? 1 :
        len <= 0x1fffff ? 2 :
            len <= 0xfffffff ? 3 : Math.floor(Math.log(len) / (Math.LN2 * 7));
    pbf.realloc(extraLen);
    for (let i = pbf.pos - 1; i >= startPos; i--)
        pbf.buf[i + extraLen] = pbf.buf[i];
}
function writePackedVarint(arr, pbf) {
    for (let i = 0; i < arr.length; i++)
        pbf.writeVarint(arr[i]);
}
function writePackedSVarint(arr, pbf) {
    for (let i = 0; i < arr.length; i++)
        pbf.writeSVarint(arr[i]);
}
function writePackedFloat(arr, pbf) {
    for (let i = 0; i < arr.length; i++)
        pbf.writeFloat(arr[i]);
}
function writePackedDouble(arr, pbf) {
    for (let i = 0; i < arr.length; i++)
        pbf.writeDouble(arr[i]);
}
function writePackedBoolean(arr, pbf) {
    for (let i = 0; i < arr.length; i++)
        pbf.writeBoolean(arr[i]);
}
function writePackedFixed32(arr, pbf) {
    for (let i = 0; i < arr.length; i++)
        pbf.writeFixed32(arr[i]);
}
function writePackedSFixed32(arr, pbf) {
    for (let i = 0; i < arr.length; i++)
        pbf.writeSFixed32(arr[i]);
}
function writePackedFixed64(arr, pbf) {
    for (let i = 0; i < arr.length; i++)
        pbf.writeFixed64(arr[i]);
}
function writePackedSFixed64(arr, pbf) {
    for (let i = 0; i < arr.length; i++)
        pbf.writeSFixed64(arr[i]);
}
function readUtf8(buf, pos, end) {
    let str = '';
    let i = pos;
    while (i < end) {
        const b0 = buf[i];
        let c = null;
        let bytesPerSequence = b0 > 0xEF ? 4 :
            b0 > 0xDF ? 3 :
                b0 > 0xBF ? 2 : 1;
        if (i + bytesPerSequence > end)
            break;
        let b1, b2, b3;
        if (bytesPerSequence === 1) {
            if (b0 < 0x80) {
                c = b0;
            }
        }
        else if (bytesPerSequence === 2) {
            b1 = buf[i + 1];
            if ((b1 & 0xC0) === 0x80) {
                c = (b0 & 0x1F) << 0x6 | (b1 & 0x3F);
                if (c <= 0x7F) {
                    c = null;
                }
            }
        }
        else if (bytesPerSequence === 3) {
            b1 = buf[i + 1];
            b2 = buf[i + 2];
            if ((b1 & 0xC0) === 0x80 && (b2 & 0xC0) === 0x80) {
                c = (b0 & 0xF) << 0xC | (b1 & 0x3F) << 0x6 | (b2 & 0x3F);
                if (c <= 0x7FF || (c >= 0xD800 && c <= 0xDFFF)) {
                    c = null;
                }
            }
        }
        else if (bytesPerSequence === 4) {
            b1 = buf[i + 1];
            b2 = buf[i + 2];
            b3 = buf[i + 3];
            if ((b1 & 0xC0) === 0x80 && (b2 & 0xC0) === 0x80 && (b3 & 0xC0) === 0x80) {
                c = (b0 & 0xF) << 0x12 | (b1 & 0x3F) << 0xC | (b2 & 0x3F) << 0x6 | (b3 & 0x3F);
                if (c <= 0xFFFF || c >= 0x110000) {
                    c = null;
                }
            }
        }
        if (c === null) {
            c = 0xFFFD;
            bytesPerSequence = 1;
        }
        else if (c > 0xFFFF) {
            c -= 0x10000;
            str += String.fromCharCode(c >>> 10 & 0x3FF | 0xD800);
            c = 0xDC00 | c & 0x3FF;
        }
        str += String.fromCharCode(c);
        i += bytesPerSequence;
    }
    return str;
}
function writeUtf8(buf, str, pos) {
    for (let i = 0, c, lead; i < str.length; i++) {
        c = str.charCodeAt(i);
        if (c > 0xD7FF && c < 0xE000) {
            if (lead) {
                if (c < 0xDC00) {
                    buf[pos++] = 0xEF;
                    buf[pos++] = 0xBF;
                    buf[pos++] = 0xBD;
                    lead = c;
                    continue;
                }
                else {
                    c = lead - 0xD800 << 10 | c - 0xDC00 | 0x10000;
                    lead = null;
                }
            }
            else {
                if (c > 0xDBFF || (i + 1 === str.length)) {
                    buf[pos++] = 0xEF;
                    buf[pos++] = 0xBF;
                    buf[pos++] = 0xBD;
                }
                else {
                    lead = c;
                }
                continue;
            }
        }
        else if (lead) {
            buf[pos++] = 0xEF;
            buf[pos++] = 0xBF;
            buf[pos++] = 0xBD;
            lead = null;
        }
        if (c < 0x80) {
            buf[pos++] = c;
        }
        else {
            if (c < 0x800) {
                buf[pos++] = c >> 0x6 | 0xC0;
            }
            else {
                if (c < 0x10000) {
                    buf[pos++] = c >> 0xC | 0xE0;
                }
                else {
                    buf[pos++] = c >> 0x12 | 0xF0;
                    buf[pos++] = c >> 0xC & 0x3F | 0x80;
                }
                buf[pos++] = c >> 0x6 & 0x3F | 0x80;
            }
            buf[pos++] = c & 0x3F | 0x80;
        }
    }
    return pos;
}

},
/* PMTiles/js/index.ts */
19: function (module, exports, __req) {
"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __exportStar = (this && this.__exportStar) || function(m, exports) {
    for (var p in m) if (p !== "default" && !Object.prototype.hasOwnProperty.call(exports, p)) __createBinding(exports, m, p);
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.PMTiles = exports.SharedPromiseCache = exports.ResolvedValueCache = exports.EtagMismatch = exports.FetchSource = exports.FileSource = exports.TileType = exports.Compression = void 0;
exports.readVarint = readVarint;
exports.zxyToTileId = zxyToTileId;
exports.tileIdToZxy = tileIdToZxy;
exports.tileTypeExt = tileTypeExt;
exports.findTile = findTile;
exports.getUint64 = getUint64;
exports.bytesToHeader = bytesToHeader;
const fflate_1 = __req(47);
const v2_1 = __importDefault(__req(48));
__exportStar(__req(49), exports);
function toNum(low, high) {
    return (high >>> 0) * 0x100000000 + (low >>> 0);
}
function readVarintRemainder(l, p) {
    const buf = p.buf;
    let b = buf[p.pos++];
    let h = (b & 0x70) >> 4;
    if (b < 0x80)
        return toNum(l, h);
    b = buf[p.pos++];
    h |= (b & 0x7f) << 3;
    if (b < 0x80)
        return toNum(l, h);
    b = buf[p.pos++];
    h |= (b & 0x7f) << 10;
    if (b < 0x80)
        return toNum(l, h);
    b = buf[p.pos++];
    h |= (b & 0x7f) << 17;
    if (b < 0x80)
        return toNum(l, h);
    b = buf[p.pos++];
    h |= (b & 0x7f) << 24;
    if (b < 0x80)
        return toNum(l, h);
    b = buf[p.pos++];
    h |= (b & 0x01) << 31;
    if (b < 0x80)
        return toNum(l, h);
    throw new Error("Expected varint not more than 10 bytes");
}
function readVarint(p) {
    const buf = p.buf;
    let b = buf[p.pos++];
    let val = b & 0x7f;
    if (b < 0x80)
        return val;
    b = buf[p.pos++];
    val |= (b & 0x7f) << 7;
    if (b < 0x80)
        return val;
    b = buf[p.pos++];
    val |= (b & 0x7f) << 14;
    if (b < 0x80)
        return val;
    b = buf[p.pos++];
    val |= (b & 0x7f) << 21;
    if (b < 0x80)
        return val;
    b = buf[p.pos];
    val |= (b & 0x0f) << 28;
    return readVarintRemainder(val, p);
}
function rotate(n, xy, rx, ry) {
    if (ry === 0) {
        if (rx === 1) {
            xy[0] = n - 1 - xy[0];
            xy[1] = n - 1 - xy[1];
        }
        const t = xy[0];
        xy[0] = xy[1];
        xy[1] = t;
    }
}
function idOnLevel(z, pos) {
    const n = 2 ** z;
    let rx = pos;
    let ry = pos;
    let t = pos;
    const xy = [0, 0];
    let s = 1;
    while (s < n) {
        rx = 1 & (t / 2);
        ry = 1 & (t ^ rx);
        rotate(s, xy, rx, ry);
        xy[0] += s * rx;
        xy[1] += s * ry;
        t = t / 4;
        s *= 2;
    }
    return [z, xy[0], xy[1]];
}
const tzValues = [
    0, 1, 5, 21, 85, 341, 1365, 5461, 21845, 87381, 349525, 1398101, 5592405,
    22369621, 89478485, 357913941, 1431655765, 5726623061, 22906492245,
    91625968981, 366503875925, 1466015503701, 5864062014805, 23456248059221,
    93824992236885, 375299968947541, 1501199875790165,
];
function zxyToTileId(z, x, y) {
    if (z > 26) {
        throw Error("Tile zoom level exceeds max safe number limit (26)");
    }
    if (x > 2 ** z - 1 || y > 2 ** z - 1) {
        throw Error("tile x/y outside zoom level bounds");
    }
    const acc = tzValues[z];
    const n = 2 ** z;
    let rx = 0;
    let ry = 0;
    let d = 0;
    const xy = [x, y];
    let s = n / 2;
    while (s > 0) {
        rx = (xy[0] & s) > 0 ? 1 : 0;
        ry = (xy[1] & s) > 0 ? 1 : 0;
        d += s * s * ((3 * rx) ^ ry);
        rotate(s, xy, rx, ry);
        s = s / 2;
    }
    return acc + d;
}
function tileIdToZxy(i) {
    let acc = 0;
    const z = 0;
    for (let z = 0; z < 27; z++) {
        const numTiles = (0x1 << z) * (0x1 << z);
        if (acc + numTiles > i) {
            return idOnLevel(z, i - acc);
        }
        acc += numTiles;
    }
    throw Error("Tile zoom level exceeds max safe number limit (26)");
}
var Compression;
(function (Compression) {
    Compression[Compression["Unknown"] = 0] = "Unknown";
    Compression[Compression["None"] = 1] = "None";
    Compression[Compression["Gzip"] = 2] = "Gzip";
    Compression[Compression["Brotli"] = 3] = "Brotli";
    Compression[Compression["Zstd"] = 4] = "Zstd";
})(Compression || (exports.Compression = Compression = {}));
async function defaultDecompress(buf, compression) {
    if (compression === Compression.None || compression === Compression.Unknown) {
        return buf;
    }
    if (compression === Compression.Gzip) {
        if (typeof globalThis.DecompressionStream === "undefined") {
            return (0, fflate_1.decompressSync)(new Uint8Array(buf));
        }
        const stream = new Response(buf).body;
        if (!stream) {
            throw Error("Failed to read response stream");
        }
        const result = stream.pipeThrough(new globalThis.DecompressionStream("gzip"));
        return new Response(result).arrayBuffer();
    }
    throw Error("Compression method not supported");
}
var TileType;
(function (TileType) {
    TileType[TileType["Unknown"] = 0] = "Unknown";
    TileType[TileType["Mvt"] = 1] = "Mvt";
    TileType[TileType["Png"] = 2] = "Png";
    TileType[TileType["Jpeg"] = 3] = "Jpeg";
    TileType[TileType["Webp"] = 4] = "Webp";
    TileType[TileType["Avif"] = 5] = "Avif";
})(TileType || (exports.TileType = TileType = {}));
function tileTypeExt(t) {
    if (t === TileType.Mvt)
        return ".mvt";
    if (t === TileType.Png)
        return ".png";
    if (t === TileType.Jpeg)
        return ".jpg";
    if (t === TileType.Webp)
        return ".webp";
    if (t === TileType.Avif)
        return ".avif";
    return "";
}
const HEADER_SIZE_BYTES = 127;
function findTile(entries, tileId) {
    let m = 0;
    let n = entries.length - 1;
    while (m <= n) {
        const k = (n + m) >> 1;
        const cmp = tileId - entries[k].tileId;
        if (cmp > 0) {
            m = k + 1;
        }
        else if (cmp < 0) {
            n = k - 1;
        }
        else {
            return entries[k];
        }
    }
    if (n >= 0) {
        if (entries[n].runLength === 0) {
            return entries[n];
        }
        if (tileId - entries[n].tileId < entries[n].runLength) {
            return entries[n];
        }
    }
    return null;
}
class FileSource {
    constructor(file) {
        this.file = file;
    }
    getKey() {
        return this.file.name;
    }
    async getBytes(offset, length) {
        const blob = this.file.slice(offset, offset + length);
        const a = await blob.arrayBuffer();
        return { data: a };
    }
}
exports.FileSource = FileSource;
class FetchSource {
    constructor(url, customHeaders = new Headers()) {
        this.url = url;
        this.customHeaders = customHeaders;
        this.mustReload = false;
        let userAgent = "";
        if ("navigator" in globalThis) {
            userAgent = globalThis.navigator.userAgent || "";
        }
        const isWindows = userAgent.indexOf("Windows") > -1;
        const isChromiumBased = /Chrome|Chromium|Edg|OPR|Brave/.test(userAgent);
        this.chromeWindowsNoCache = false;
        if (isWindows && isChromiumBased) {
            this.chromeWindowsNoCache = true;
        }
    }
    getKey() {
        return this.url;
    }
    setHeaders(customHeaders) {
        this.customHeaders = customHeaders;
    }
    async getBytes(offset, length, passedSignal, etag) {
        let controller;
        let signal;
        if (passedSignal) {
            signal = passedSignal;
        }
        else {
            controller = new AbortController();
            signal = controller.signal;
        }
        const requestHeaders = new Headers(this.customHeaders);
        requestHeaders.set("range", `bytes=${offset}-${offset + length - 1}`);
        let cache;
        if (this.mustReload) {
            cache = "reload";
        }
        else if (this.chromeWindowsNoCache) {
            cache = "no-store";
        }
        let resp = await fetch(this.url, {
            signal: signal,
            cache: cache,
            headers: requestHeaders,
        });
        if (offset === 0 && resp.status === 416) {
            const contentRange = resp.headers.get("Content-Range");
            if (!contentRange || !contentRange.startsWith("bytes */")) {
                throw Error("Missing content-length on 416 response");
            }
            const actualLength = +contentRange.substr(8);
            resp = await fetch(this.url, {
                signal: signal,
                cache: "reload",
                headers: { range: `bytes=0-${actualLength - 1}` },
            });
        }
        let newEtag = resp.headers.get("Etag");
        if (newEtag === null || newEtag === void 0 ? void 0 : newEtag.startsWith("W/")) {
            newEtag = null;
        }
        if (resp.status === 416 || (etag && newEtag && newEtag !== etag)) {
            this.mustReload = true;
            throw new EtagMismatch(`Server returned non-matching ETag ${etag} after one retry. Check browser extensions and servers for issues that may affect correct ETag headers.`);
        }
        if (resp.status >= 300) {
            throw Error(`Bad response code: ${resp.status}`);
        }
        const contentLength = resp.headers.get("Content-Length");
        if (resp.status === 200 && (!contentLength || +contentLength > length)) {
            if (controller)
                controller.abort();
            throw Error("Server returned no content-length header or content-length exceeding request. Check that your storage backend supports HTTP Byte Serving.");
        }
        const a = await resp.arrayBuffer();
        return {
            data: a,
            etag: newEtag || undefined,
            cacheControl: resp.headers.get("Cache-Control") || undefined,
            expires: resp.headers.get("Expires") || undefined,
        };
    }
}
exports.FetchSource = FetchSource;
function getUint64(v, offset) {
    const wh = v.getUint32(offset + 4, true);
    const wl = v.getUint32(offset + 0, true);
    return wh * 2 ** 32 + wl;
}
function bytesToHeader(bytes, etag) {
    const v = new DataView(bytes);
    const specVersion = v.getUint8(7);
    if (specVersion > 3) {
        throw Error(`Archive is spec version ${specVersion} but this library supports up to spec version 3`);
    }
    return {
        specVersion: specVersion,
        rootDirectoryOffset: getUint64(v, 8),
        rootDirectoryLength: getUint64(v, 16),
        jsonMetadataOffset: getUint64(v, 24),
        jsonMetadataLength: getUint64(v, 32),
        leafDirectoryOffset: getUint64(v, 40),
        leafDirectoryLength: getUint64(v, 48),
        tileDataOffset: getUint64(v, 56),
        tileDataLength: getUint64(v, 64),
        numAddressedTiles: getUint64(v, 72),
        numTileEntries: getUint64(v, 80),
        numTileContents: getUint64(v, 88),
        clustered: v.getUint8(96) === 1,
        internalCompression: v.getUint8(97),
        tileCompression: v.getUint8(98),
        tileType: v.getUint8(99),
        minZoom: v.getUint8(100),
        maxZoom: v.getUint8(101),
        minLon: v.getInt32(102, true) / 10000000,
        minLat: v.getInt32(106, true) / 10000000,
        maxLon: v.getInt32(110, true) / 10000000,
        maxLat: v.getInt32(114, true) / 10000000,
        centerZoom: v.getUint8(118),
        centerLon: v.getInt32(119, true) / 10000000,
        centerLat: v.getInt32(123, true) / 10000000,
        etag: etag,
    };
}
function deserializeIndex(buffer) {
    const p = { buf: new Uint8Array(buffer), pos: 0 };
    const numEntries = readVarint(p);
    const entries = [];
    let lastId = 0;
    for (let i = 0; i < numEntries; i++) {
        const v = readVarint(p);
        entries.push({ tileId: lastId + v, offset: 0, length: 0, runLength: 1 });
        lastId += v;
    }
    for (let i = 0; i < numEntries; i++) {
        entries[i].runLength = readVarint(p);
    }
    for (let i = 0; i < numEntries; i++) {
        entries[i].length = readVarint(p);
    }
    for (let i = 0; i < numEntries; i++) {
        const v = readVarint(p);
        if (v === 0 && i > 0) {
            entries[i].offset = entries[i - 1].offset + entries[i - 1].length;
        }
        else {
            entries[i].offset = v - 1;
        }
    }
    return entries;
}
function detectVersion(a) {
    const v = new DataView(a);
    if (v.getUint16(2, true) === 2) {
        console.warn("PMTiles spec version 2 has been deprecated; please see github.com/protomaps/PMTiles for tools to upgrade");
        return 2;
    }
    if (v.getUint16(2, true) === 1) {
        console.warn("PMTiles spec version 1 has been deprecated; please see github.com/protomaps/PMTiles for tools to upgrade");
        return 1;
    }
    return 3;
}
class EtagMismatch extends Error {
}
exports.EtagMismatch = EtagMismatch;
async function getHeaderAndRoot(source, decompress) {
    const resp = await source.getBytes(0, 16384);
    const v = new DataView(resp.data);
    if (v.getUint16(0, true) !== 0x4d50) {
        throw new Error("Wrong magic number for PMTiles archive");
    }
    if (detectVersion(resp.data) < 3) {
        return [await v2_1.default.getHeader(source)];
    }
    const headerData = resp.data.slice(0, HEADER_SIZE_BYTES);
    const header = bytesToHeader(headerData, resp.etag);
    const rootDirData = resp.data.slice(header.rootDirectoryOffset, header.rootDirectoryOffset + header.rootDirectoryLength);
    const dirKey = `${source.getKey()}|${header.etag || ""}|${header.rootDirectoryOffset}|${header.rootDirectoryLength}`;
    const rootDir = deserializeIndex(await decompress(rootDirData, header.internalCompression));
    return [header, [dirKey, rootDir.length, rootDir]];
}
async function getDirectory(source, decompress, offset, length, header) {
    const resp = await source.getBytes(offset, length, undefined, header.etag);
    const data = await decompress(resp.data, header.internalCompression);
    const directory = deserializeIndex(data);
    if (directory.length === 0) {
        throw new Error("Empty directory is invalid");
    }
    return directory;
}
class ResolvedValueCache {
    constructor(maxCacheEntries = 100, prefetch = true, decompress = defaultDecompress) {
        this.cache = new Map();
        this.maxCacheEntries = maxCacheEntries;
        this.counter = 1;
        this.decompress = decompress;
    }
    async getHeader(source) {
        const cacheKey = source.getKey();
        const cacheValue = this.cache.get(cacheKey);
        if (cacheValue) {
            cacheValue.lastUsed = this.counter++;
            const data = cacheValue.data;
            return data;
        }
        const res = await getHeaderAndRoot(source, this.decompress);
        if (res[1]) {
            this.cache.set(res[1][0], {
                lastUsed: this.counter++,
                data: res[1][2],
            });
        }
        this.cache.set(cacheKey, {
            lastUsed: this.counter++,
            data: res[0],
        });
        this.prune();
        return res[0];
    }
    async getDirectory(source, offset, length, header) {
        const cacheKey = `${source.getKey()}|${header.etag || ""}|${offset}|${length}`;
        const cacheValue = this.cache.get(cacheKey);
        if (cacheValue) {
            cacheValue.lastUsed = this.counter++;
            const data = cacheValue.data;
            return data;
        }
        const directory = await getDirectory(source, this.decompress, offset, length, header);
        this.cache.set(cacheKey, {
            lastUsed: this.counter++,
            data: directory,
        });
        this.prune();
        return directory;
    }
    async getArrayBuffer(source, offset, length, header) {
        const cacheKey = `${source.getKey()}|${header.etag || ""}|${offset}|${length}`;
        const cacheValue = this.cache.get(cacheKey);
        if (cacheValue) {
            cacheValue.lastUsed = this.counter++;
            const data = await cacheValue.data;
            return data;
        }
        const resp = await source.getBytes(offset, length, undefined, header.etag);
        this.cache.set(cacheKey, {
            lastUsed: this.counter++,
            data: resp.data,
        });
        this.prune();
        return resp.data;
    }
    prune() {
        if (this.cache.size > this.maxCacheEntries) {
            let minUsed = Infinity;
            let minKey = undefined;
            this.cache.forEach((cacheValue, key) => {
                if (cacheValue.lastUsed < minUsed) {
                    minUsed = cacheValue.lastUsed;
                    minKey = key;
                }
            });
            if (minKey) {
                this.cache.delete(minKey);
            }
        }
    }
    async invalidate(source) {
        this.cache.delete(source.getKey());
    }
}
exports.ResolvedValueCache = ResolvedValueCache;
class SharedPromiseCache {
    constructor(maxCacheEntries = 100, prefetch = true, decompress = defaultDecompress) {
        this.cache = new Map();
        this.invalidations = new Map();
        this.maxCacheEntries = maxCacheEntries;
        this.counter = 1;
        this.decompress = decompress;
    }
    async getHeader(source) {
        const cacheKey = source.getKey();
        const cacheValue = this.cache.get(cacheKey);
        if (cacheValue) {
            cacheValue.lastUsed = this.counter++;
            const data = await cacheValue.data;
            return data;
        }
        const p = new Promise((resolve, reject) => {
            getHeaderAndRoot(source, this.decompress)
                .then((res) => {
                if (res[1]) {
                    this.cache.set(res[1][0], {
                        lastUsed: this.counter++,
                        data: Promise.resolve(res[1][2]),
                    });
                }
                resolve(res[0]);
                this.prune();
            })
                .catch((e) => {
                reject(e);
            });
        });
        this.cache.set(cacheKey, { lastUsed: this.counter++, data: p });
        return p;
    }
    async getDirectory(source, offset, length, header) {
        const cacheKey = `${source.getKey()}|${header.etag || ""}|${offset}|${length}`;
        const cacheValue = this.cache.get(cacheKey);
        if (cacheValue) {
            cacheValue.lastUsed = this.counter++;
            const data = await cacheValue.data;
            return data;
        }
        const p = new Promise((resolve, reject) => {
            getDirectory(source, this.decompress, offset, length, header)
                .then((directory) => {
                resolve(directory);
                this.prune();
            })
                .catch((e) => {
                reject(e);
            });
        });
        this.cache.set(cacheKey, { lastUsed: this.counter++, data: p });
        return p;
    }
    async getArrayBuffer(source, offset, length, header) {
        const cacheKey = `${source.getKey()}|${header.etag || ""}|${offset}|${length}`;
        const cacheValue = this.cache.get(cacheKey);
        if (cacheValue) {
            cacheValue.lastUsed = this.counter++;
            const data = await cacheValue.data;
            return data;
        }
        const p = new Promise((resolve, reject) => {
            source
                .getBytes(offset, length, undefined, header.etag)
                .then((resp) => {
                resolve(resp.data);
                if (this.cache.has(cacheKey)) {
                }
                this.prune();
            })
                .catch((e) => {
                reject(e);
            });
        });
        this.cache.set(cacheKey, { lastUsed: this.counter++, data: p });
        return p;
    }
    prune() {
        if (this.cache.size >= this.maxCacheEntries) {
            let minUsed = Infinity;
            let minKey = undefined;
            this.cache.forEach((cacheValue, key) => {
                if (cacheValue.lastUsed < minUsed) {
                    minUsed = cacheValue.lastUsed;
                    minKey = key;
                }
            });
            if (minKey) {
                this.cache.delete(minKey);
            }
        }
    }
    async invalidate(source) {
        const key = source.getKey();
        if (this.invalidations.get(key)) {
            return await this.invalidations.get(key);
        }
        this.cache.delete(source.getKey());
        const p = new Promise((resolve, reject) => {
            this.getHeader(source)
                .then((h) => {
                resolve();
                this.invalidations.delete(key);
            })
                .catch((e) => {
                reject(e);
            });
        });
        this.invalidations.set(key, p);
    }
}
exports.SharedPromiseCache = SharedPromiseCache;
class PMTiles {
    constructor(source, cache, decompress) {
        if (typeof source === "string") {
            this.source = new FetchSource(source);
        }
        else {
            this.source = source;
        }
        if (decompress) {
            this.decompress = decompress;
        }
        else {
            this.decompress = defaultDecompress;
        }
        if (cache) {
            this.cache = cache;
        }
        else {
            this.cache = new SharedPromiseCache();
        }
    }
    async getHeader() {
        return await this.cache.getHeader(this.source);
    }
    async getZxyAttempt(z, x, y, signal) {
        const tileId = zxyToTileId(z, x, y);
        const header = await this.cache.getHeader(this.source);
        if (header.specVersion < 3) {
            return v2_1.default.getZxy(header, this.source, this.cache, z, x, y, signal);
        }
        if (z < header.minZoom || z > header.maxZoom) {
            return undefined;
        }
        let dO = header.rootDirectoryOffset;
        let dL = header.rootDirectoryLength;
        for (let depth = 0; depth <= 3; depth++) {
            const directory = await this.cache.getDirectory(this.source, dO, dL, header);
            const entry = findTile(directory, tileId);
            if (entry) {
                if (entry.runLength > 0) {
                    const resp = await this.source.getBytes(header.tileDataOffset + entry.offset, entry.length, signal, header.etag);
                    return {
                        data: await this.decompress(resp.data, header.tileCompression),
                        cacheControl: resp.cacheControl,
                        expires: resp.expires,
                    };
                }
                dO = header.leafDirectoryOffset + entry.offset;
                dL = entry.length;
            }
            else {
                return undefined;
            }
        }
        throw Error("Maximum directory depth exceeded");
    }
    async getZxy(z, x, y, signal) {
        try {
            return await this.getZxyAttempt(z, x, y, signal);
        }
        catch (e) {
            if (e instanceof EtagMismatch) {
                this.cache.invalidate(this.source);
                return await this.getZxyAttempt(z, x, y, signal);
            }
            throw e;
        }
    }
    async getMetadataAttempt() {
        const header = await this.cache.getHeader(this.source);
        const resp = await this.source.getBytes(header.jsonMetadataOffset, header.jsonMetadataLength, undefined, header.etag);
        const decompressed = await this.decompress(resp.data, header.internalCompression);
        const dec = new TextDecoder("utf-8");
        return JSON.parse(dec.decode(decompressed));
    }
    async getMetadata() {
        try {
            return await this.getMetadataAttempt();
        }
        catch (e) {
            if (e instanceof EtagMismatch) {
                this.cache.invalidate(this.source);
                return await this.getMetadataAttempt();
            }
            throw e;
        }
    }
    async getTileJson(baseTilesUrl) {
        const header = await this.getHeader();
        const metadata = (await this.getMetadata());
        const ext = tileTypeExt(header.tileType);
        return {
            tilejson: "3.0.0",
            scheme: "xyz",
            tiles: [`${baseTilesUrl}/{z}/{x}/{y}${ext}`],
            vector_layers: metadata.vector_layers,
            attribution: metadata.attribution,
            description: metadata.description,
            name: metadata.name,
            version: metadata.version,
            bounds: [header.minLon, header.minLat, header.maxLon, header.maxLat],
            center: [header.centerLon, header.centerLat, header.centerZoom],
            minzoom: header.minZoom,
            maxzoom: header.maxZoom,
        };
    }
}
exports.PMTiles = PMTiles;

},
/* rbush/index.js */
20: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const quickselect_1 = __importDefault(__req(50));
class RBush {
    constructor(maxEntries = 9) {
        this._maxEntries = Math.max(4, maxEntries);
        this._minEntries = Math.max(2, Math.ceil(this._maxEntries * 0.4));
        this.clear();
    }
    all() {
        return this._all(this.data, []);
    }
    search(bbox) {
        let node = this.data;
        const result = [];
        if (!intersects(bbox, node))
            return result;
        const toBBox = this.toBBox;
        const nodesToSearch = [];
        while (node) {
            for (let i = 0; i < node.children.length; i++) {
                const child = node.children[i];
                const childBBox = node.leaf ? toBBox(child) : child;
                if (intersects(bbox, childBBox)) {
                    if (node.leaf)
                        result.push(child);
                    else if (contains(bbox, childBBox))
                        this._all(child, result);
                    else
                        nodesToSearch.push(child);
                }
            }
            node = nodesToSearch.pop();
        }
        return result;
    }
    collides(bbox) {
        let node = this.data;
        if (!intersects(bbox, node))
            return false;
        const nodesToSearch = [];
        while (node) {
            for (let i = 0; i < node.children.length; i++) {
                const child = node.children[i];
                const childBBox = node.leaf ? this.toBBox(child) : child;
                if (intersects(bbox, childBBox)) {
                    if (node.leaf || contains(bbox, childBBox))
                        return true;
                    nodesToSearch.push(child);
                }
            }
            node = nodesToSearch.pop();
        }
        return false;
    }
    load(data) {
        if (!(data && data.length))
            return this;
        if (data.length < this._minEntries) {
            for (let i = 0; i < data.length; i++) {
                this.insert(data[i]);
            }
            return this;
        }
        let node = this._build(data.slice(), 0, data.length - 1, 0);
        if (!this.data.children.length) {
            this.data = node;
        }
        else if (this.data.height === node.height) {
            this._splitRoot(this.data, node);
        }
        else {
            if (this.data.height < node.height) {
                const tmpNode = this.data;
                this.data = node;
                node = tmpNode;
            }
            this._insert(node, this.data.height - node.height - 1, true);
        }
        return this;
    }
    insert(item) {
        if (item)
            this._insert(item, this.data.height - 1);
        return this;
    }
    clear() {
        this.data = createNode([]);
        return this;
    }
    remove(item, equalsFn) {
        if (!item)
            return this;
        let node = this.data;
        const bbox = this.toBBox(item);
        const path = [];
        const indexes = [];
        let i, parent, goingUp;
        while (node || path.length) {
            if (!node) {
                node = path.pop();
                parent = path[path.length - 1];
                i = indexes.pop();
                goingUp = true;
            }
            if (node.leaf) {
                const index = findItem(item, node.children, equalsFn);
                if (index !== -1) {
                    node.children.splice(index, 1);
                    path.push(node);
                    this._condense(path);
                    return this;
                }
            }
            if (!goingUp && !node.leaf && contains(node, bbox)) {
                path.push(node);
                indexes.push(i);
                i = 0;
                parent = node;
                node = node.children[0];
            }
            else if (parent) {
                i++;
                node = parent.children[i];
                goingUp = false;
            }
            else
                node = null;
        }
        return this;
    }
    toBBox(item) { return item; }
    compareMinX(a, b) { return a.minX - b.minX; }
    compareMinY(a, b) { return a.minY - b.minY; }
    toJSON() { return this.data; }
    fromJSON(data) {
        this.data = data;
        return this;
    }
    _all(node, result) {
        const nodesToSearch = [];
        while (node) {
            if (node.leaf)
                result.push(...node.children);
            else
                nodesToSearch.push(...node.children);
            node = nodesToSearch.pop();
        }
        return result;
    }
    _build(items, left, right, height) {
        const N = right - left + 1;
        let M = this._maxEntries;
        let node;
        if (N <= M) {
            node = createNode(items.slice(left, right + 1));
            calcBBox(node, this.toBBox);
            return node;
        }
        if (!height) {
            height = Math.ceil(Math.log(N) / Math.log(M));
            M = Math.ceil(N / Math.pow(M, height - 1));
        }
        node = createNode([]);
        node.leaf = false;
        node.height = height;
        const N2 = Math.ceil(N / M);
        const N1 = N2 * Math.ceil(Math.sqrt(M));
        multiSelect(items, left, right, N1, this.compareMinX);
        for (let i = left; i <= right; i += N1) {
            const right2 = Math.min(i + N1 - 1, right);
            multiSelect(items, i, right2, N2, this.compareMinY);
            for (let j = i; j <= right2; j += N2) {
                const right3 = Math.min(j + N2 - 1, right2);
                node.children.push(this._build(items, j, right3, height - 1));
            }
        }
        calcBBox(node, this.toBBox);
        return node;
    }
    _chooseSubtree(bbox, node, level, path) {
        while (true) {
            path.push(node);
            if (node.leaf || path.length - 1 === level)
                break;
            let minArea = Infinity;
            let minEnlargement = Infinity;
            let targetNode;
            for (let i = 0; i < node.children.length; i++) {
                const child = node.children[i];
                const area = bboxArea(child);
                const enlargement = enlargedArea(bbox, child) - area;
                if (enlargement < minEnlargement) {
                    minEnlargement = enlargement;
                    minArea = area < minArea ? area : minArea;
                    targetNode = child;
                }
                else if (enlargement === minEnlargement) {
                    if (area < minArea) {
                        minArea = area;
                        targetNode = child;
                    }
                }
            }
            node = targetNode || node.children[0];
        }
        return node;
    }
    _insert(item, level, isNode) {
        const bbox = isNode ? item : this.toBBox(item);
        const insertPath = [];
        const node = this._chooseSubtree(bbox, this.data, level, insertPath);
        node.children.push(item);
        extend(node, bbox);
        while (level >= 0) {
            if (insertPath[level].children.length > this._maxEntries) {
                this._split(insertPath, level);
                level--;
            }
            else
                break;
        }
        this._adjustParentBBoxes(bbox, insertPath, level);
    }
    _split(insertPath, level) {
        const node = insertPath[level];
        const M = node.children.length;
        const m = this._minEntries;
        this._chooseSplitAxis(node, m, M);
        const splitIndex = this._chooseSplitIndex(node, m, M);
        const newNode = createNode(node.children.splice(splitIndex, node.children.length - splitIndex));
        newNode.height = node.height;
        newNode.leaf = node.leaf;
        calcBBox(node, this.toBBox);
        calcBBox(newNode, this.toBBox);
        if (level)
            insertPath[level - 1].children.push(newNode);
        else
            this._splitRoot(node, newNode);
    }
    _splitRoot(node, newNode) {
        this.data = createNode([node, newNode]);
        this.data.height = node.height + 1;
        this.data.leaf = false;
        calcBBox(this.data, this.toBBox);
    }
    _chooseSplitIndex(node, m, M) {
        let index;
        let minOverlap = Infinity;
        let minArea = Infinity;
        for (let i = m; i <= M - m; i++) {
            const bbox1 = distBBox(node, 0, i, this.toBBox);
            const bbox2 = distBBox(node, i, M, this.toBBox);
            const overlap = intersectionArea(bbox1, bbox2);
            const area = bboxArea(bbox1) + bboxArea(bbox2);
            if (overlap < minOverlap) {
                minOverlap = overlap;
                index = i;
                minArea = area < minArea ? area : minArea;
            }
            else if (overlap === minOverlap) {
                if (area < minArea) {
                    minArea = area;
                    index = i;
                }
            }
        }
        return index || M - m;
    }
    _chooseSplitAxis(node, m, M) {
        const compareMinX = node.leaf ? this.compareMinX : compareNodeMinX;
        const compareMinY = node.leaf ? this.compareMinY : compareNodeMinY;
        const xMargin = this._allDistMargin(node, m, M, compareMinX);
        const yMargin = this._allDistMargin(node, m, M, compareMinY);
        if (xMargin < yMargin)
            node.children.sort(compareMinX);
    }
    _allDistMargin(node, m, M, compare) {
        node.children.sort(compare);
        const toBBox = this.toBBox;
        const leftBBox = distBBox(node, 0, m, toBBox);
        const rightBBox = distBBox(node, M - m, M, toBBox);
        let margin = bboxMargin(leftBBox) + bboxMargin(rightBBox);
        for (let i = m; i < M - m; i++) {
            const child = node.children[i];
            extend(leftBBox, node.leaf ? toBBox(child) : child);
            margin += bboxMargin(leftBBox);
        }
        for (let i = M - m - 1; i >= m; i--) {
            const child = node.children[i];
            extend(rightBBox, node.leaf ? toBBox(child) : child);
            margin += bboxMargin(rightBBox);
        }
        return margin;
    }
    _adjustParentBBoxes(bbox, path, level) {
        for (let i = level; i >= 0; i--) {
            extend(path[i], bbox);
        }
    }
    _condense(path) {
        for (let i = path.length - 1, siblings; i >= 0; i--) {
            if (path[i].children.length === 0) {
                if (i > 0) {
                    siblings = path[i - 1].children;
                    siblings.splice(siblings.indexOf(path[i]), 1);
                }
                else
                    this.clear();
            }
            else
                calcBBox(path[i], this.toBBox);
        }
    }
}
exports.default = RBush;
function findItem(item, items, equalsFn) {
    if (!equalsFn)
        return items.indexOf(item);
    for (let i = 0; i < items.length; i++) {
        if (equalsFn(item, items[i]))
            return i;
    }
    return -1;
}
function calcBBox(node, toBBox) {
    distBBox(node, 0, node.children.length, toBBox, node);
}
function distBBox(node, k, p, toBBox, destNode) {
    if (!destNode)
        destNode = createNode(null);
    destNode.minX = Infinity;
    destNode.minY = Infinity;
    destNode.maxX = -Infinity;
    destNode.maxY = -Infinity;
    for (let i = k; i < p; i++) {
        const child = node.children[i];
        extend(destNode, node.leaf ? toBBox(child) : child);
    }
    return destNode;
}
function extend(a, b) {
    a.minX = Math.min(a.minX, b.minX);
    a.minY = Math.min(a.minY, b.minY);
    a.maxX = Math.max(a.maxX, b.maxX);
    a.maxY = Math.max(a.maxY, b.maxY);
    return a;
}
function compareNodeMinX(a, b) { return a.minX - b.minX; }
function compareNodeMinY(a, b) { return a.minY - b.minY; }
function bboxArea(a) { return (a.maxX - a.minX) * (a.maxY - a.minY); }
function bboxMargin(a) { return (a.maxX - a.minX) + (a.maxY - a.minY); }
function enlargedArea(a, b) {
    return (Math.max(b.maxX, a.maxX) - Math.min(b.minX, a.minX)) *
        (Math.max(b.maxY, a.maxY) - Math.min(b.minY, a.minY));
}
function intersectionArea(a, b) {
    const minX = Math.max(a.minX, b.minX);
    const minY = Math.max(a.minY, b.minY);
    const maxX = Math.min(a.maxX, b.maxX);
    const maxY = Math.min(a.maxY, b.maxY);
    return Math.max(0, maxX - minX) *
        Math.max(0, maxY - minY);
}
function contains(a, b) {
    return a.minX <= b.minX &&
        a.minY <= b.minY &&
        b.maxX <= a.maxX &&
        b.maxY <= a.maxY;
}
function intersects(a, b) {
    return b.minX <= a.maxX &&
        b.minY <= a.maxY &&
        b.maxX >= a.minX &&
        b.maxY >= a.minY;
}
function createNode(children) {
    return {
        children,
        height: 1,
        leaf: true,
        minX: Infinity,
        minY: Infinity,
        maxX: -Infinity,
        maxY: -Infinity
    };
}
function multiSelect(arr, left, right, n, compare) {
    const stack = [left, right];
    while (stack.length) {
        right = stack.pop();
        left = stack.pop();
        if (right - left <= n)
            continue;
        const mid = left + Math.ceil((right - left) / n / 2) * n;
        (0, quickselect_1.default)(arr, mid, left, right, compare);
        stack.push(left, mid, mid, right);
    }
}

},
/* basemaps/styles/src/base_layers.ts */
21: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.nolabels_layers = nolabels_layers;
exports.labels_layers = labels_layers;
const language_1 = __req(23);
function nolabels_layers(source, t) {
    return [
        {
            id: "background",
            type: "background",
            paint: {
                "background-color": t.background,
            },
        },
        {
            id: "earth",
            type: "fill",
            filter: ["==", "$type", "Polygon"],
            source: source,
            "source-layer": "earth",
            paint: {
                "fill-color": t.earth,
            },
        },
        ...(t.landcover
            ? [
                {
                    id: "landcover",
                    type: "fill",
                    source: source,
                    "source-layer": "landcover",
                    paint: {
                        "fill-color": [
                            "match",
                            ["get", "kind"],
                            "grassland",
                            t.landcover.grassland,
                            "barren",
                            t.landcover.barren,
                            "urban_area",
                            t.landcover.urban_area,
                            "farmland",
                            t.landcover.farmland,
                            "glacier",
                            t.landcover.glacier,
                            "scrub",
                            t.landcover.scrub,
                            t.landcover.forest,
                        ],
                        "fill-opacity": ["interpolate", ["linear"], ["zoom"], 5, 1, 7, 0],
                    },
                },
            ]
            : []),
        {
            id: "landuse_park",
            type: "fill",
            source: source,
            "source-layer": "landuse",
            filter: [
                "in",
                "kind",
                "national_park",
                "park",
                "cemetery",
                "protected_area",
                "nature_reserve",
                "forest",
                "golf_course",
                "wood",
                "nature_reserve",
                "forest",
                "scrub",
                "grassland",
                "grass",
                "military",
                "naval_base",
                "airfield",
            ],
            paint: {
                "fill-opacity": ["interpolate", ["linear"], ["zoom"], 6, 0, 11, 1],
                "fill-color": [
                    "case",
                    [
                        "in",
                        ["get", "kind"],
                        [
                            "literal",
                            [
                                "national_park",
                                "park",
                                "cemetery",
                                "protected_area",
                                "nature_reserve",
                                "forest",
                                "golf_course",
                            ],
                        ],
                    ],
                    t.park_b,
                    [
                        "in",
                        ["get", "kind"],
                        ["literal", ["wood", "nature_reserve", "forest"]],
                    ],
                    t.wood_b,
                    ["in", ["get", "kind"], ["literal", ["scrub", "grassland", "grass"]]],
                    t.scrub_b,
                    ["in", ["get", "kind"], ["literal", ["glacier"]]],
                    t.glacier,
                    ["in", ["get", "kind"], ["literal", ["sand"]]],
                    t.sand,
                    [
                        "in",
                        ["get", "kind"],
                        ["literal", ["military", "naval_base", "airfield"]],
                    ],
                    t.zoo,
                    t.earth,
                ],
            },
        },
        {
            id: "landuse_urban_green",
            type: "fill",
            source: source,
            "source-layer": "landuse",
            filter: ["in", "kind", "allotments", "village_green", "playground"],
            paint: {
                "fill-color": t.park_b,
                "fill-opacity": 0.7,
            },
        },
        {
            id: "landuse_hospital",
            type: "fill",
            source: source,
            "source-layer": "landuse",
            filter: ["==", "kind", "hospital"],
            paint: {
                "fill-color": t.hospital,
            },
        },
        {
            id: "landuse_industrial",
            type: "fill",
            source: source,
            "source-layer": "landuse",
            filter: ["==", "kind", "industrial"],
            paint: {
                "fill-color": t.industrial,
            },
        },
        {
            id: "landuse_school",
            type: "fill",
            source: source,
            "source-layer": "landuse",
            filter: ["in", "kind", "school", "university", "college"],
            paint: {
                "fill-color": t.school,
            },
        },
        {
            id: "landuse_beach",
            type: "fill",
            source: source,
            "source-layer": "landuse",
            filter: ["in", "kind", "beach"],
            paint: {
                "fill-color": t.beach,
            },
        },
        {
            id: "landuse_zoo",
            type: "fill",
            source: source,
            "source-layer": "landuse",
            filter: ["in", "kind", "zoo"],
            paint: {
                "fill-color": t.zoo,
            },
        },
        {
            id: "landuse_aerodrome",
            type: "fill",
            source: source,
            "source-layer": "landuse",
            filter: ["in", "kind", "aerodrome"],
            paint: {
                "fill-color": t.aerodrome,
            },
        },
        {
            id: "roads_runway",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: ["==", "kind_detail", "runway"],
            paint: {
                "line-color": t.runway,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    10,
                    0,
                    12,
                    4,
                    18,
                    30,
                ],
            },
        },
        {
            id: "roads_taxiway",
            type: "line",
            source: source,
            "source-layer": "roads",
            minzoom: 13,
            filter: ["==", "kind_detail", "taxiway"],
            paint: {
                "line-color": t.runway,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    13,
                    0,
                    13.5,
                    1,
                    15,
                    6,
                ],
            },
        },
        {
            id: "landuse_runway",
            type: "fill",
            source: source,
            "source-layer": "landuse",
            filter: ["any", ["in", "kind", "runway", "taxiway"]],
            paint: {
                "fill-color": t.runway,
            },
        },
        {
            id: "water",
            type: "fill",
            filter: ["==", "$type", "Polygon"],
            source: source,
            "source-layer": "water",
            paint: {
                "fill-color": t.water,
            },
        },
        {
            id: "water_stream",
            type: "line",
            source: source,
            "source-layer": "water",
            minzoom: 14,
            filter: ["in", "kind", "stream"],
            paint: {
                "line-color": t.water,
                "line-width": 0.5,
            },
        },
        {
            id: "water_river",
            type: "line",
            source: source,
            "source-layer": "water",
            minzoom: 9,
            filter: ["in", "kind", "river"],
            paint: {
                "line-color": t.water,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    9,
                    0,
                    9.5,
                    1.0,
                    18,
                    12,
                ],
            },
        },
        {
            id: "landuse_pedestrian",
            type: "fill",
            source: source,
            "source-layer": "landuse",
            filter: ["==", "kind", "pedestrian"],
            paint: {
                "fill-color": t.pedestrian,
            },
        },
        {
            id: "landuse_pier",
            type: "fill",
            source: source,
            "source-layer": "landuse",
            filter: ["==", "kind", "pier"],
            paint: {
                "fill-color": t.pier,
            },
        },
        {
            id: "roads_tunnels_other_casing",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: ["all", ["has", "is_tunnel"], ["in", "kind", "other", "path"]],
            paint: {
                "line-color": t.tunnel_other_casing,
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    14,
                    0,
                    20,
                    7,
                ],
            },
        },
        {
            id: "roads_tunnels_minor_casing",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: ["all", ["has", "is_tunnel"], ["==", "kind", "minor_road"]],
            paint: {
                "line-color": t.tunnel_minor_casing,
                "line-dasharray": [3, 2],
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    11,
                    0,
                    12.5,
                    0.5,
                    15,
                    2,
                    18,
                    11,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    12,
                    0,
                    12.5,
                    1,
                ],
            },
        },
        {
            id: "roads_tunnels_link_casing",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: ["all", ["has", "is_tunnel"], ["has", "is_link"]],
            paint: {
                "line-color": t.tunnel_link_casing,
                "line-dasharray": [3, 2],
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    13,
                    0,
                    13.5,
                    1,
                    18,
                    11,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    12,
                    0,
                    12.5,
                    1,
                ],
            },
        },
        {
            id: "roads_tunnels_major_casing",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: [
                "all",
                ["!has", "is_tunnel"],
                ["!has", "is_bridge"],
                ["==", "kind", "major_road"],
            ],
            paint: {
                "line-color": t.tunnel_major_casing,
                "line-dasharray": [3, 2],
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    7,
                    0,
                    7.5,
                    0.5,
                    18,
                    13,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    9,
                    0,
                    9.5,
                    1,
                ],
            },
        },
        {
            id: "roads_tunnels_highway_casing",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: [
                "all",
                ["!has", "is_tunnel"],
                ["!has", "is_bridge"],
                ["==", "kind", "highway"],
                ["!has", "is_link"],
            ],
            paint: {
                "line-color": t.tunnel_highway_casing,
                "line-dasharray": [6, 0.5],
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    3,
                    0,
                    3.5,
                    0.5,
                    18,
                    15,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    7,
                    0,
                    7.5,
                    1,
                    20,
                    15,
                ],
            },
        },
        {
            id: "roads_tunnels_other",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: ["all", ["has", "is_tunnel"], ["in", "kind", "other", "path"]],
            paint: {
                "line-color": t.tunnel_other,
                "line-dasharray": [4.5, 0.5],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    14,
                    0,
                    20,
                    7,
                ],
            },
        },
        {
            id: "roads_tunnels_minor",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: ["all", ["has", "is_tunnel"], ["==", "kind", "minor_road"]],
            paint: {
                "line-color": t.tunnel_minor,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    11,
                    0,
                    12.5,
                    0.5,
                    15,
                    2,
                    18,
                    11,
                ],
            },
        },
        {
            id: "roads_tunnels_link",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: ["all", ["has", "is_tunnel"], ["has", "is_link"]],
            paint: {
                "line-color": t.tunnel_minor,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    13,
                    0,
                    13.5,
                    1,
                    18,
                    11,
                ],
            },
        },
        {
            id: "roads_tunnels_major",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: ["all", ["has", "is_tunnel"], ["==", "kind", "major_road"]],
            paint: {
                "line-color": t.tunnel_major,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    6,
                    0,
                    12,
                    1.6,
                    15,
                    3,
                    18,
                    13,
                ],
            },
        },
        {
            id: "roads_tunnels_highway",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: [
                "all",
                ["has", "is_tunnel"],
                ["==", ["get", "kind"], "highway"],
                ["!", ["has", "is_link"]],
            ],
            paint: {
                "line-color": t.tunnel_highway,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    3,
                    0,
                    6,
                    1.1,
                    12,
                    1.6,
                    15,
                    5,
                    18,
                    15,
                ],
            },
        },
        {
            id: "buildings",
            type: "fill",
            source: source,
            "source-layer": "buildings",
            filter: ["in", "kind", "building", "building_part"],
            paint: {
                "fill-color": t.buildings,
                "fill-opacity": 0.5,
            },
        },
        {
            id: "roads_pier",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: ["==", "kind_detail", "pier"],
            paint: {
                "line-color": t.pier,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    12,
                    0,
                    12.5,
                    0.5,
                    20,
                    16,
                ],
            },
        },
        {
            id: "roads_minor_service_casing",
            type: "line",
            source: source,
            "source-layer": "roads",
            minzoom: 13,
            filter: [
                "all",
                ["!has", "is_tunnel"],
                ["!has", "is_bridge"],
                ["==", "kind", "minor_road"],
                ["==", "kind_detail", "service"],
            ],
            paint: {
                "line-color": t.minor_service_casing,
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    13,
                    0,
                    18,
                    8,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    13,
                    0,
                    13.5,
                    0.8,
                ],
            },
        },
        {
            id: "roads_minor_casing",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: [
                "all",
                ["!has", "is_tunnel"],
                ["!has", "is_bridge"],
                ["==", "kind", "minor_road"],
                ["!=", "kind_detail", "service"],
            ],
            paint: {
                "line-color": t.minor_casing,
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    11,
                    0,
                    12.5,
                    0.5,
                    15,
                    2,
                    18,
                    11,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    12,
                    0,
                    12.5,
                    1,
                ],
            },
        },
        {
            id: "roads_link_casing",
            type: "line",
            source: source,
            "source-layer": "roads",
            minzoom: 13,
            filter: ["has", "is_link"],
            paint: {
                "line-color": t.minor_casing,
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    13,
                    0,
                    13.5,
                    1,
                    18,
                    11,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    13,
                    0,
                    13.5,
                    1.5,
                ],
            },
        },
        {
            id: "roads_major_casing_late",
            type: "line",
            source: source,
            "source-layer": "roads",
            minzoom: 12,
            filter: [
                "all",
                ["!has", "is_tunnel"],
                ["!has", "is_bridge"],
                ["==", "kind", "major_road"],
            ],
            paint: {
                "line-color": t.major_casing_late,
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    6,
                    0,
                    12,
                    1.6,
                    15,
                    3,
                    18,
                    13,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    9,
                    0,
                    9.5,
                    1,
                ],
            },
        },
        {
            id: "roads_highway_casing_late",
            type: "line",
            source: source,
            "source-layer": "roads",
            minzoom: 12,
            filter: [
                "all",
                ["!has", "is_tunnel"],
                ["!has", "is_bridge"],
                ["==", "kind", "highway"],
                ["!has", "is_link"],
            ],
            paint: {
                "line-color": t.highway_casing_late,
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    3,
                    0,
                    3.5,
                    0.5,
                    18,
                    15,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    7,
                    0,
                    7.5,
                    1,
                    20,
                    15,
                ],
            },
        },
        {
            id: "roads_other",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: [
                "all",
                ["!has", "is_tunnel"],
                ["!has", "is_bridge"],
                ["in", "kind", "other", "path"],
                ["!=", "kind_detail", "pier"],
            ],
            paint: {
                "line-color": t.other,
                "line-dasharray": [3, 1],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    14,
                    0,
                    20,
                    7,
                ],
            },
        },
        {
            id: "roads_link",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: ["has", "is_link"],
            paint: {
                "line-color": t.link,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    13,
                    0,
                    13.5,
                    1,
                    18,
                    11,
                ],
            },
        },
        {
            id: "roads_minor_service",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: [
                "all",
                ["!has", "is_tunnel"],
                ["!has", "is_bridge"],
                ["==", "kind", "minor_road"],
                ["==", "kind_detail", "service"],
            ],
            paint: {
                "line-color": t.minor_service,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    13,
                    0,
                    18,
                    8,
                ],
            },
        },
        {
            id: "roads_minor",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: [
                "all",
                ["!has", "is_tunnel"],
                ["!has", "is_bridge"],
                ["==", "kind", "minor_road"],
                ["!=", "kind_detail", "service"],
            ],
            paint: {
                "line-color": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    11,
                    t.minor_a,
                    16,
                    t.minor_b,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    11,
                    0,
                    12.5,
                    0.5,
                    15,
                    2,
                    18,
                    11,
                ],
            },
        },
        {
            id: "roads_major_casing_early",
            type: "line",
            source: source,
            "source-layer": "roads",
            maxzoom: 12,
            filter: [
                "all",
                ["!has", "is_tunnel"],
                ["!has", "is_bridge"],
                ["==", "kind", "major_road"],
            ],
            paint: {
                "line-color": t.major_casing_early,
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    7,
                    0,
                    7.5,
                    0.5,
                    18,
                    13,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    9,
                    0,
                    9.5,
                    1,
                ],
            },
        },
        {
            id: "roads_major",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: [
                "all",
                ["!has", "is_tunnel"],
                ["!has", "is_bridge"],
                ["==", "kind", "major_road"],
            ],
            paint: {
                "line-color": t.major,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    6,
                    0,
                    12,
                    1.6,
                    15,
                    3,
                    18,
                    13,
                ],
            },
        },
        {
            id: "roads_highway_casing_early",
            type: "line",
            source: source,
            "source-layer": "roads",
            maxzoom: 12,
            filter: [
                "all",
                ["!has", "is_tunnel"],
                ["!has", "is_bridge"],
                ["==", "kind", "highway"],
                ["!has", "is_link"],
            ],
            paint: {
                "line-color": t.highway_casing_early,
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    3,
                    0,
                    3.5,
                    0.5,
                    18,
                    15,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    7,
                    0,
                    7.5,
                    1,
                ],
            },
        },
        {
            id: "roads_highway",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: [
                "all",
                ["!has", "is_tunnel"],
                ["!has", "is_bridge"],
                ["==", "kind", "highway"],
                ["!has", "is_link"],
            ],
            paint: {
                "line-color": t.highway,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    3,
                    0,
                    6,
                    1.1,
                    12,
                    1.6,
                    15,
                    5,
                    18,
                    15,
                ],
            },
        },
        {
            id: "roads_rail",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: ["==", "kind", "rail"],
            paint: {
                "line-dasharray": [0.3, 0.75],
                "line-opacity": 0.5,
                "line-color": t.railway,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    3,
                    0,
                    6,
                    0.15,
                    18,
                    9,
                ],
            },
        },
        {
            id: "boundaries_country",
            type: "line",
            source: source,
            "source-layer": "boundaries",
            filter: ["<=", "kind_detail", 2],
            paint: {
                "line-color": t.boundaries,
                "line-width": 0.7,
                "line-dasharray": [
                    "step",
                    ["zoom"],
                    ["literal", [2]],
                    4,
                    ["literal", [2, 1]],
                ],
            },
        },
        {
            id: "boundaries",
            type: "line",
            source: source,
            "source-layer": "boundaries",
            filter: [">", "kind_detail", 2],
            paint: {
                "line-color": t.boundaries,
                "line-width": 0.4,
                "line-dasharray": [
                    "step",
                    ["zoom"],
                    ["literal", [2]],
                    4,
                    ["literal", [2, 1]],
                ],
            },
        },
        {
            id: "roads_bridges_other_casing",
            type: "line",
            source: source,
            "source-layer": "roads",
            minzoom: 12,
            filter: ["all", ["has", "is_bridge"], ["in", "kind", "other", "path"]],
            paint: {
                "line-color": t.bridges_other_casing,
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    14,
                    0,
                    20,
                    7,
                ],
            },
        },
        {
            id: "roads_bridges_link_casing",
            type: "line",
            source: source,
            "source-layer": "roads",
            minzoom: 12,
            filter: ["all", ["has", "is_bridge"], ["has", "is_link"]],
            paint: {
                "line-color": t.bridges_minor_casing,
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    13,
                    0,
                    13.5,
                    1,
                    18,
                    11,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    12,
                    0,
                    12.5,
                    1.5,
                ],
            },
        },
        {
            id: "roads_bridges_minor_casing",
            type: "line",
            source: source,
            "source-layer": "roads",
            minzoom: 12,
            filter: ["all", ["has", "is_bridge"], ["==", "kind", "minor_road"]],
            paint: {
                "line-color": t.bridges_minor_casing,
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    11,
                    0,
                    12.5,
                    0.5,
                    15,
                    2,
                    18,
                    11,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    13,
                    0,
                    13.5,
                    0.8,
                ],
            },
        },
        {
            id: "roads_bridges_major_casing",
            type: "line",
            source: source,
            "source-layer": "roads",
            minzoom: 12,
            filter: ["all", ["has", "is_bridge"], ["==", "kind", "major_road"]],
            paint: {
                "line-color": t.bridges_major_casing,
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    7,
                    0,
                    7.5,
                    0.5,
                    18,
                    10,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    9,
                    0,
                    9.5,
                    1.5,
                ],
            },
        },
        {
            id: "roads_bridges_other",
            type: "line",
            source: source,
            "source-layer": "roads",
            minzoom: 12,
            filter: ["all", ["has", "is_bridge"], ["in", "kind", "other", "path"]],
            paint: {
                "line-color": t.bridges_other,
                "line-dasharray": [2, 1],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    14,
                    0,
                    20,
                    7,
                ],
            },
        },
        {
            id: "roads_bridges_minor",
            type: "line",
            source: source,
            "source-layer": "roads",
            minzoom: 12,
            filter: ["all", ["has", "is_bridge"], ["==", "kind", "minor_road"]],
            paint: {
                "line-color": t.bridges_minor,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    11,
                    0,
                    12.5,
                    0.5,
                    15,
                    2,
                    18,
                    11,
                ],
            },
        },
        {
            id: "roads_bridges_link",
            type: "line",
            source: source,
            "source-layer": "roads",
            minzoom: 12,
            filter: ["all", ["has", "is_bridge"], ["has", "is_link"]],
            paint: {
                "line-color": t.bridges_minor,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    13,
                    0,
                    13.5,
                    1,
                    18,
                    11,
                ],
            },
        },
        {
            id: "roads_bridges_major",
            type: "line",
            source: source,
            "source-layer": "roads",
            minzoom: 12,
            filter: ["all", ["has", "is_bridge"], ["==", "kind", "major_road"]],
            paint: {
                "line-color": t.bridges_major,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    6,
                    0,
                    12,
                    1.6,
                    15,
                    3,
                    18,
                    13,
                ],
            },
        },
        {
            id: "roads_bridges_highway_casing",
            type: "line",
            source: source,
            "source-layer": "roads",
            minzoom: 12,
            filter: [
                "all",
                ["has", "is_bridge"],
                ["==", "kind", "highway"],
                ["!has", "is_link"],
            ],
            paint: {
                "line-color": t.bridges_highway_casing,
                "line-gap-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    3,
                    0,
                    3.5,
                    0.5,
                    18,
                    15,
                ],
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    7,
                    0,
                    7.5,
                    1,
                    20,
                    15,
                ],
            },
        },
        {
            id: "roads_bridges_highway",
            type: "line",
            source: source,
            "source-layer": "roads",
            filter: [
                "all",
                ["has", "is_bridge"],
                ["==", "kind", "highway"],
                ["!has", "is_link"],
            ],
            paint: {
                "line-color": t.bridges_highway,
                "line-width": [
                    "interpolate",
                    ["exponential", 1.6],
                    ["zoom"],
                    3,
                    0,
                    6,
                    1.1,
                    12,
                    1.6,
                    15,
                    5,
                    18,
                    15,
                ],
            },
        },
    ];
}
function labels_layers(source, t, lang, script) {
    return [
        {
            id: "address_label",
            type: "symbol",
            source: source,
            "source-layer": "buildings",
            minzoom: 18,
            filter: ["==", "kind", "address"],
            layout: {
                "symbol-placement": "point",
                "text-font": [t.italic || "Noto Sans Italic"],
                "text-field": ["get", "addr_housenumber"],
                "text-size": 12,
            },
            paint: {
                "text-color": t.address_label,
                "text-halo-color": t.address_label_halo,
                "text-halo-width": 1,
            },
        },
        {
            id: "water_waterway_label",
            type: "symbol",
            source: source,
            "source-layer": "water",
            minzoom: 13,
            filter: ["in", "kind", "river", "stream"],
            layout: {
                "symbol-placement": "line",
                "text-font": [t.italic || "Noto Sans Italic"],
                "text-field": (0, language_1.get_multiline_name)(lang, script, t.regular),
                "text-size": 12,
                "text-letter-spacing": 0.2,
            },
            paint: {
                "text-color": t.ocean_label,
                "text-halo-color": t.water,
                "text-halo-width": 1,
            },
        },
        {
            id: "roads_labels_minor",
            type: "symbol",
            source: source,
            "source-layer": "roads",
            minzoom: 15,
            filter: ["in", "kind", "minor_road", "other", "path"],
            layout: {
                "symbol-sort-key": ["get", "min_zoom"],
                "symbol-placement": "line",
                "text-font": [t.regular || "Noto Sans Regular"],
                "text-field": (0, language_1.get_multiline_name)(lang, script, t.regular),
                "text-size": 12,
            },
            paint: {
                "text-color": t.roads_label_minor,
                "text-halo-color": t.roads_label_minor_halo,
                "text-halo-width": 1,
            },
        },
        {
            id: "water_label_ocean",
            type: "symbol",
            source: source,
            "source-layer": "water",
            filter: ["in", "kind", "sea", "ocean", "bay", "strait", "fjord"],
            layout: {
                "text-font": [t.italic || "Noto Sans Italic"],
                "text-field": (0, language_1.get_multiline_name)(lang, script, t.regular),
                "text-size": ["interpolate", ["linear"], ["zoom"], 3, 10, 10, 12],
                "text-letter-spacing": 0.1,
                "text-max-width": 9,
                "text-transform": "uppercase",
            },
            paint: {
                "text-color": t.ocean_label,
                "text-halo-width": 1,
                "text-halo-color": t.water,
            },
        },
        {
            id: "water_label_lakes",
            type: "symbol",
            source: source,
            "source-layer": "water",
            filter: ["in", "kind", "lake", "water"],
            layout: {
                "text-font": [t.italic || "Noto Sans Italic"],
                "text-field": (0, language_1.get_multiline_name)(lang, script, t.regular),
                "text-size": [
                    "interpolate",
                    ["linear"],
                    ["zoom"],
                    3,
                    10,
                    6,
                    12,
                    10,
                    12,
                ],
                "text-letter-spacing": 0.1,
                "text-max-width": 9,
            },
            paint: {
                "text-color": t.ocean_label,
                "text-halo-color": t.water,
                "text-halo-width": 1,
            },
        },
        {
            id: "roads_labels_major",
            type: "symbol",
            source: source,
            "source-layer": "roads",
            minzoom: 11,
            filter: ["in", "kind", "highway", "major_road"],
            layout: {
                "symbol-sort-key": ["get", "min_zoom"],
                "symbol-placement": "line",
                "text-font": [t.regular || "Noto Sans Regular"],
                "text-field": (0, language_1.get_multiline_name)(lang, script, t.regular),
                "text-size": 12,
            },
            paint: {
                "text-color": t.roads_label_major,
                "text-halo-color": t.roads_label_major_halo,
                "text-halo-width": 1,
            },
        },
        ...(t.pois
            ? [
                {
                    id: "pois",
                    type: "symbol",
                    source: source,
                    "source-layer": "pois",
                    filter: [
                        "all",
                        [
                            "in",
                            ["get", "kind"],
                            [
                                "literal",
                                [
                                    "beach",
                                    "forest",
                                    "marina",
                                    "park",
                                    "peak",
                                    "zoo",
                                    "garden",
                                    "bench",
                                    "aerodrome",
                                    "station",
                                    "bus_stop",
                                    "ferry_terminal",
                                    "stadium",
                                    "university",
                                    "library",
                                    "school",
                                    "animal",
                                    "toilets",
                                    "drinking_water",
                                ],
                            ],
                        ],
                        [">=", ["zoom"], ["+", ["get", "min_zoom"], 0]],
                    ],
                    layout: {
                        "icon-image": [
                            "match",
                            ["get", "kind"],
                            "station",
                            "train_station",
                            ["get", "kind"],
                        ],
                        "text-font": [t.regular || "Noto Sans Regular"],
                        "text-justify": "auto",
                        "text-field": (0, language_1.get_multiline_name)(lang, script, t.regular),
                        "text-size": [
                            "interpolate",
                            ["linear"],
                            ["zoom"],
                            17,
                            10,
                            19,
                            16,
                        ],
                        "text-max-width": 8,
                        "text-offset": [1.1, 0],
                        "text-variable-anchor": ["left", "right"],
                    },
                    paint: {
                        "text-color": [
                            "case",
                            [
                                "in",
                                ["get", "kind"],
                                [
                                    "literal",
                                    [
                                        "beach",
                                        "forest",
                                        "marina",
                                        "park",
                                        "peak",
                                        "zoo",
                                        "garden",
                                        "bench",
                                    ],
                                ],
                            ],
                            t.pois.green,
                            [
                                "in",
                                ["get", "kind"],
                                [
                                    "literal",
                                    ["aerodrome", "station", "bus_stop", "ferry_terminal"],
                                ],
                            ],
                            t.pois.lapis,
                            [
                                "in",
                                ["get", "kind"],
                                [
                                    "literal",
                                    [
                                        "stadium",
                                        "university",
                                        "library",
                                        "school",
                                        "animal",
                                        "toilets",
                                        "drinking_water",
                                    ],
                                ],
                            ],
                            t.pois.slategray,
                            t.earth,
                        ],
                        "text-halo-color": t.earth,
                        "text-halo-width": 1,
                    },
                },
            ]
            : []),
        {
            id: "places_subplace",
            type: "symbol",
            source: source,
            "source-layer": "places",
            filter: ["==", "kind", "neighbourhood"],
            layout: {
                "symbol-sort-key": ["get", "min_zoom"],
                "text-field": (0, language_1.get_multiline_name)(lang, script, t.regular),
                "text-font": [t.regular || "Noto Sans Regular"],
                "text-max-width": 7,
                "text-letter-spacing": 0.1,
                "text-padding": [
                    "interpolate",
                    ["linear"],
                    ["zoom"],
                    5,
                    2,
                    8,
                    4,
                    12,
                    18,
                    15,
                    20,
                ],
                "text-size": [
                    "interpolate",
                    ["exponential", 1.2],
                    ["zoom"],
                    11,
                    8,
                    14,
                    14,
                    18,
                    24,
                ],
                "text-transform": "uppercase",
            },
            paint: {
                "text-color": t.subplace_label,
                "text-halo-color": t.subplace_label_halo,
                "text-halo-width": 1,
            },
        },
        {
            id: "places_locality",
            type: "symbol",
            source: source,
            "source-layer": "places",
            filter: ["==", "kind", "locality"],
            layout: {
                "icon-image": ["step", ["zoom"], "townspot", 8, ""],
                "icon-size": 0.7,
                "text-field": (0, language_1.get_multiline_name)(lang, script, t.regular),
                "text-font": [
                    "case",
                    ["<=", ["get", "min_zoom"], 5],
                    ["literal", [t.bold || "Noto Sans Medium"]],
                    ["literal", [t.regular || "Noto Sans Regular"]],
                ],
                "text-padding": [
                    "interpolate",
                    ["linear"],
                    ["zoom"],
                    5,
                    3,
                    8,
                    7,
                    12,
                    11,
                ],
                "text-size": [
                    "interpolate",
                    ["linear"],
                    ["zoom"],
                    2,
                    [
                        "case",
                        ["<", ["get", "population_rank"], 13],
                        8,
                        [">=", ["get", "population_rank"], 13],
                        13,
                        0,
                    ],
                    4,
                    [
                        "case",
                        ["<", ["get", "population_rank"], 13],
                        10,
                        [">=", ["get", "population_rank"], 13],
                        15,
                        0,
                    ],
                    6,
                    [
                        "case",
                        ["<", ["get", "population_rank"], 12],
                        11,
                        [">=", ["get", "population_rank"], 12],
                        17,
                        0,
                    ],
                    8,
                    [
                        "case",
                        ["<", ["get", "population_rank"], 11],
                        11,
                        [">=", ["get", "population_rank"], 11],
                        18,
                        0,
                    ],
                    10,
                    [
                        "case",
                        ["<", ["get", "population_rank"], 9],
                        12,
                        [">=", ["get", "population_rank"], 9],
                        20,
                        0,
                    ],
                    15,
                    [
                        "case",
                        ["<", ["get", "population_rank"], 8],
                        12,
                        [">=", ["get", "population_rank"], 8],
                        22,
                        0,
                    ],
                ],
                "icon-padding": [
                    "interpolate",
                    ["linear"],
                    ["zoom"],
                    0,
                    0,
                    8,
                    4,
                    10,
                    8,
                    12,
                    6,
                    22,
                    2,
                ],
                "text-justify": "auto",
                "text-anchor": ["step", ["zoom"], "left", 8, "center"],
                "text-radial-offset": 0.4,
            },
            paint: {
                "text-color": t.city_label,
                "text-halo-color": t.city_label_halo,
                "text-halo-width": 1,
            },
        },
        {
            id: "places_region",
            type: "symbol",
            source: source,
            "source-layer": "places",
            filter: ["==", "kind", "region"],
            layout: {
                "symbol-sort-key": ["get", "min_zoom"],
                "text-field": [
                    "step",
                    ["zoom"],
                    ["get", "name:short"],
                    6,
                    (0, language_1.get_multiline_name)(lang, script, t.regular),
                ],
                "text-font": [t.regular || "Noto Sans Regular"],
                "text-size": ["interpolate", ["linear"], ["zoom"], 3, 11, 7, 16],
                "text-radial-offset": 0.2,
                "text-anchor": "center",
                "text-transform": "uppercase",
            },
            paint: {
                "text-color": t.state_label,
                "text-halo-color": t.state_label_halo,
                "text-halo-width": 1,
            },
        },
        {
            id: "places_country",
            type: "symbol",
            source: source,
            "source-layer": "places",
            filter: ["==", "kind", "country"],
            layout: {
                "symbol-sort-key": ["get", "min_zoom"],
                "text-field": (0, language_1.get_country_name)(lang, script),
                "text-font": [t.bold || "Noto Sans Medium"],
                "text-size": [
                    "interpolate",
                    ["linear"],
                    ["zoom"],
                    2,
                    [
                        "case",
                        ["<", ["get", "population_rank"], 10],
                        8,
                        [">=", ["get", "population_rank"], 10],
                        12,
                        0,
                    ],
                    6,
                    [
                        "case",
                        ["<", ["get", "population_rank"], 8],
                        10,
                        [">=", ["get", "population_rank"], 8],
                        18,
                        0,
                    ],
                    8,
                    [
                        "case",
                        ["<", ["get", "population_rank"], 7],
                        11,
                        [">=", ["get", "population_rank"], 7],
                        20,
                        0,
                    ],
                ],
                "icon-padding": [
                    "interpolate",
                    ["linear"],
                    ["zoom"],
                    0,
                    2,
                    14,
                    2,
                    16,
                    20,
                    17,
                    2,
                    22,
                    2,
                ],
                "text-transform": "uppercase",
            },
            paint: {
                "text-color": t.country_label,
                "text-halo-color": t.earth,
                "text-halo-width": 1,
            },
        },
    ];
}

},
/* basemaps/styles/src/flavors.ts */
22: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.BLACK = exports.GRAYSCALE = exports.WHITE = exports.DARK = exports.LIGHT = void 0;
exports.LIGHT = {
    background: "#cccccc",
    earth: "#e2dfda",
    park_a: "#cfddd5",
    park_b: "#9cd3b4",
    hospital: "#e4dad9",
    industrial: "#d1dde1",
    school: "#e4ded7",
    wood_a: "#d0ded0",
    wood_b: "#a0d9a0",
    pedestrian: "#e3e0d4",
    scrub_a: "#cedcd7",
    scrub_b: "#99d2bb",
    glacier: "#e7e7e7",
    sand: "#e2e0d7",
    beach: "#e8e4d0",
    aerodrome: "#dadbdf",
    runway: "#e9e9ed",
    water: "#80deea",
    zoo: "#c6dcdc",
    military: "#dcdcdc",
    tunnel_other_casing: "#e0e0e0",
    tunnel_minor_casing: "#e0e0e0",
    tunnel_link_casing: "#e0e0e0",
    tunnel_major_casing: "#e0e0e0",
    tunnel_highway_casing: "#e0e0e0",
    tunnel_other: "#d5d5d5",
    tunnel_minor: "#d5d5d5",
    tunnel_link: "#d5d5d5",
    tunnel_major: "#d5d5d5",
    tunnel_highway: "#d5d5d5",
    pier: "#e0e0e0",
    buildings: "#cccccc",
    minor_service_casing: "#e0e0e0",
    minor_casing: "#e0e0e0",
    link_casing: "#e0e0e0",
    major_casing_late: "#e0e0e0",
    highway_casing_late: "#e0e0e0",
    other: "#ebebeb",
    minor_service: "#ebebeb",
    minor_a: "#ebebeb",
    minor_b: "#ffffff",
    link: "#ffffff",
    major_casing_early: "#e0e0e0",
    major: "#ffffff",
    highway_casing_early: "#e0e0e0",
    highway: "#ffffff",
    railway: "#a7b1b3",
    boundaries: "#adadad",
    bridges_other_casing: "#e0e0e0",
    bridges_minor_casing: "#e0e0e0",
    bridges_link_casing: "#e0e0e0",
    bridges_major_casing: "#e0e0e0",
    bridges_highway_casing: "#e0e0e0",
    bridges_other: "#ebebeb",
    bridges_minor: "#ffffff",
    bridges_link: "#ffffff",
    bridges_major: "#f5f5f5",
    bridges_highway: "#ffffff",
    roads_label_minor: "#91888b",
    roads_label_minor_halo: "#ffffff",
    roads_label_major: "#938a8d",
    roads_label_major_halo: "#ffffff",
    ocean_label: "#728dd4",
    subplace_label: "#8f8f8f",
    subplace_label_halo: "#e0e0e0",
    city_label: "#5c5c5c",
    city_label_halo: "#e0e0e0",
    state_label: "#b3b3b3",
    state_label_halo: "#e0e0e0",
    country_label: "#a3a3a3",
    address_label: "#91888b",
    address_label_halo: "#ffffff",
    pois: {
        blue: "#1A8CBD",
        green: "#20834D",
        lapis: "#315BCF",
        pink: "#EF56BA",
        red: "#F2567A",
        slategray: "#6A5B8F",
        tangerine: "#CB6704",
        turquoise: "#00C3D4",
    },
    landcover: {
        grassland: "rgba(210, 239, 207, 1)",
        barren: "rgba(255, 243, 215, 1)",
        urban_area: "rgba(230, 230, 230, 1)",
        farmland: "rgba(216, 239, 210, 1)",
        glacier: "rgba(255, 255, 255, 1)",
        scrub: "rgba(234, 239, 210, 1)",
        forest: "rgba(196, 231, 210, 1)",
    },
};
exports.DARK = {
    background: "#34373d",
    earth: "#1f1f1f",
    park_a: "#1c2421",
    park_b: "#192a24",
    hospital: "#252424",
    industrial: "#222222",
    school: "#262323",
    wood_a: "#202121",
    wood_b: "#202121",
    pedestrian: "#1e1e1e",
    scrub_a: "#222323",
    scrub_b: "#222323",
    glacier: "#1c1c1c",
    sand: "#212123",
    beach: "#28282a",
    aerodrome: "#1e1e1e",
    runway: "#333333",
    water: "#31353f",
    zoo: "#222323",
    military: "#242323",
    tunnel_other_casing: "#141414",
    tunnel_minor_casing: "#141414",
    tunnel_link_casing: "#141414",
    tunnel_major_casing: "#141414",
    tunnel_highway_casing: "#141414",
    tunnel_other: "#292929",
    tunnel_minor: "#292929",
    tunnel_link: "#292929",
    tunnel_major: "#292929",
    tunnel_highway: "#292929",
    pier: "#333333",
    buildings: "#111111",
    minor_service_casing: "#1f1f1f",
    minor_casing: "#1f1f1f",
    link_casing: "#1f1f1f",
    major_casing_late: "#1f1f1f",
    highway_casing_late: "#1f1f1f",
    other: "#333333",
    minor_service: "#333333",
    minor_a: "#3d3d3d",
    minor_b: "#333333",
    link: "#3d3d3d",
    major_casing_early: "#1f1f1f",
    major: "#3d3d3d",
    highway_casing_early: "#1f1f1f",
    highway: "#474747",
    railway: "#000000",
    boundaries: "#5b6374",
    bridges_other_casing: "#2b2b2b",
    bridges_minor_casing: "#1f1f1f",
    bridges_link_casing: "#1f1f1f",
    bridges_major_casing: "#1f1f1f",
    bridges_highway_casing: "#1f1f1f",
    bridges_other: "#333333",
    bridges_minor: "#333333",
    bridges_link: "#3d3d3d",
    bridges_major: "#3d3d3d",
    bridges_highway: "#474747",
    roads_label_minor: "#525252",
    roads_label_minor_halo: "#1f1f1f",
    roads_label_major: "#666666",
    roads_label_major_halo: "#1f1f1f",
    ocean_label: "#717784",
    subplace_label: "#525252",
    subplace_label_halo: "#1f1f1f",
    city_label: "#7a7a7a",
    city_label_halo: "#212121",
    state_label: "#3d3d3d",
    state_label_halo: "#1f1f1f",
    country_label: "#5c5c5c",
    address_label: "#525252",
    address_label_halo: "#1f1f1f",
    pois: {
        blue: "#4299BB",
        green: "#30C573",
        lapis: "#2B5CEA",
        pink: "#EF56BA",
        red: "#F2567A",
        slategray: "#93939F",
        tangerine: "#F19B6E",
        turquoise: "#00C3D4",
    },
    landcover: {
        grassland: "rgba(30, 41, 31, 1)",
        barren: "rgba(38, 38, 36, 1)",
        urban_area: "rgba(28, 28, 28, 1)",
        farmland: "rgba(31, 36, 32, 1)",
        glacier: "rgba(43, 43, 43, 1)",
        scrub: "rgba(34, 36, 30, 1)",
        forest: "rgba(28, 41, 37, 1)",
    },
};
exports.WHITE = {
    background: "#ffffff",
    earth: "#ffffff",
    park_a: "#fcfcfc",
    park_b: "#fcfcfc",
    hospital: "#f8f8f8",
    industrial: "#fcfcfc",
    school: "#f8f8f8",
    wood_a: "#fafafa",
    wood_b: "#fafafa",
    pedestrian: "#fdfdfd",
    scrub_a: "#fafafa",
    scrub_b: "#fafafa",
    glacier: "#fcfcfc",
    sand: "#fafafa",
    beach: "#f6f6f6",
    aerodrome: "#fdfdfd",
    runway: "#efefef",
    water: "#dcdcdc",
    zoo: "#f7f7f7",
    military: "#fcfcfc",
    tunnel_other_casing: "#d6d6d6",
    tunnel_minor_casing: "#fcfcfc",
    tunnel_link_casing: "#fcfcfc",
    tunnel_major_casing: "#fcfcfc",
    tunnel_highway_casing: "#fcfcfc",
    tunnel_other: "#d6d6d6",
    tunnel_minor: "#d6d6d6",
    tunnel_link: "#d6d6d6",
    tunnel_major: "#d6d6d6",
    tunnel_highway: "#d6d6d6",
    pier: "#efefef",
    buildings: "#efefef",
    minor_service_casing: "#ffffff",
    minor_casing: "#ffffff",
    link_casing: "#ffffff",
    major_casing_late: "#ffffff",
    highway_casing_late: "#ffffff",
    other: "#f5f5f5",
    minor_service: "#f5f5f5",
    minor_a: "#ebebeb",
    minor_b: "#f5f5f5",
    link: "#ebebeb",
    major_casing_early: "#ffffff",
    major: "#ebebeb",
    highway_casing_early: "#ffffff",
    highway: "#ebebeb",
    railway: "#d6d6d6",
    boundaries: "#adadad",
    bridges_other_casing: "#ffffff",
    bridges_minor_casing: "#ffffff",
    bridges_link_casing: "#ffffff",
    bridges_major_casing: "#ffffff",
    bridges_highway_casing: "#ffffff",
    bridges_other: "#f5f5f5",
    bridges_minor: "#f5f5f5",
    bridges_link: "#ebebeb",
    bridges_major: "#ebebeb",
    bridges_highway: "#ebebeb",
    roads_label_minor: "#adadad",
    roads_label_minor_halo: "#ffffff",
    roads_label_major: "#999999",
    roads_label_major_halo: "#ffffff",
    ocean_label: "#adadad",
    subplace_label: "#8f8f8f",
    subplace_label_halo: "#ffffff",
    city_label: "#5c5c5c",
    city_label_halo: "#ffffff",
    state_label: "#b3b3b3",
    state_label_halo: "#ffffff",
    country_label: "#b8b8b8",
    address_label: "#adadad",
    address_label_halo: "#ffffff",
};
exports.GRAYSCALE = {
    background: "#a3a3a3",
    earth: "#cccccc",
    park_a: "#c2c2c2",
    park_b: "#c2c2c2",
    hospital: "#d0d0d0",
    industrial: "#c6c6c6",
    school: "#d0d0d0",
    wood_a: "#c2c2c2",
    wood_b: "#c2c2c2",
    pedestrian: "#c4c4c4",
    scrub_a: "#c2c2c2",
    scrub_b: "#c2c2c2",
    glacier: "#d2d2d2",
    sand: "#d2d2d2",
    beach: "#d2d2d2",
    aerodrome: "#c9c9c9",
    runway: "#f5f5f5",
    water: "#a3a3a3",
    zoo: "#c7c7c7",
    military: "#bfbfbf",
    tunnel_other_casing: "#b8b8b8",
    tunnel_minor_casing: "#b8b8b8",
    tunnel_link_casing: "#b8b8b8",
    tunnel_major_casing: "#b8b8b8",
    tunnel_highway_casing: "#b8b8b8",
    tunnel_other: "#d6d6d6",
    tunnel_minor: "#d6d6d6",
    tunnel_link: "#d6d6d6",
    tunnel_major: "#d6d6d6",
    tunnel_highway: "#d6d6d6",
    pier: "#b8b8b8",
    buildings: "#e0e0e0",
    minor_service_casing: "#cccccc",
    minor_casing: "#cccccc",
    link_casing: "#cccccc",
    major_casing_late: "#cccccc",
    highway_casing_late: "#cccccc",
    other: "#e0e0e0",
    minor_service: "#e0e0e0",
    minor_a: "#ebebeb",
    minor_b: "#e0e0e0",
    link: "#ebebeb",
    major_casing_early: "#cccccc",
    major: "#ebebeb",
    highway_casing_early: "#cccccc",
    highway: "#ebebeb",
    railway: "#f5f5f5",
    boundaries: "#5c5c5c",
    bridges_other_casing: "#cccccc",
    bridges_minor_casing: "#cccccc",
    bridges_link_casing: "#cccccc",
    bridges_major_casing: "#cccccc",
    bridges_highway_casing: "#cccccc",
    bridges_other: "#e0e0e0",
    bridges_minor: "#e0e0e0",
    bridges_link: "#ebebeb",
    bridges_major: "#ebebeb",
    bridges_highway: "#ebebeb",
    roads_label_minor: "#999999",
    roads_label_minor_halo: "#e0e0e0",
    roads_label_major: "#8f8f8f",
    roads_label_major_halo: "#ebebeb",
    ocean_label: "#7a7a7a",
    subplace_label: "#7a7a7a",
    subplace_label_halo: "#cccccc",
    city_label: "#474747",
    city_label_halo: "#cccccc",
    state_label: "#999999",
    state_label_halo: "#cccccc",
    country_label: "#858585",
    address_label: "#999999",
    address_label_halo: "#e0e0e0",
};
exports.BLACK = {
    background: "#2b2b2b",
    earth: "#141414",
    park_a: "#181818",
    park_b: "#181818",
    hospital: "#1d1d1d",
    industrial: "#101010",
    school: "#111111",
    wood_a: "#1a1a1a",
    wood_b: "#1a1a1a",
    pedestrian: "#191919",
    scrub_a: "#1c1c1c",
    scrub_b: "#1c1c1c",
    glacier: "#191919",
    sand: "#161616",
    beach: "#1f1f1f",
    aerodrome: "#191919",
    runway: "#323232",
    water: "#333333",
    zoo: "#191919",
    military: "#121212",
    tunnel_other_casing: "#101010",
    tunnel_minor_casing: "#101010",
    tunnel_link_casing: "#101010",
    tunnel_major_casing: "#101010",
    tunnel_highway_casing: "#101010",
    tunnel_other: "#292929",
    tunnel_minor: "#292929",
    tunnel_link: "#292929",
    tunnel_major: "#292929",
    tunnel_highway: "#292929",
    pier: "#0a0a0a",
    buildings: "#0a0a0a",
    minor_service_casing: "#141414",
    minor_casing: "#141414",
    link_casing: "#141414",
    major_casing_late: "#141414",
    highway_casing_late: "#141414",
    other: "#1f1f1f",
    minor_service: "#1f1f1f",
    minor_a: "#292929",
    minor_b: "#1f1f1f",
    link: "#1f1f1f",
    major_casing_early: "#141414",
    major: "#292929",
    highway_casing_early: "#141414",
    highway: "#292929",
    railway: "#292929",
    boundaries: "#707070",
    bridges_other_casing: "#141414",
    bridges_minor_casing: "#141414",
    bridges_link_casing: "#141414",
    bridges_major_casing: "#141414",
    bridges_highway_casing: "#141414",
    bridges_other: "#1f1f1f",
    bridges_minor: "#1f1f1f",
    bridges_link: "#292929",
    bridges_major: "#292929",
    bridges_highway: "#292929",
    roads_label_minor: "#525252",
    roads_label_minor_halo: "#141414",
    roads_label_major: "#5c5c5c",
    roads_label_major_halo: "#141414",
    ocean_label: "#707070",
    subplace_label: "#5c5c5c",
    subplace_label_halo: "#141414",
    city_label: "#999999",
    city_label_halo: "#141414",
    state_label: "#3d3d3d",
    state_label_halo: "#141414",
    country_label: "#707070",
    address_label: "#525252",
    address_label_halo: "#141414",
};

},
/* basemaps/styles/src/language.ts */
23: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.language_script_pairs = void 0;
exports.get_country_name = get_country_name;
exports.get_multiline_name = get_multiline_name;
function get_name_block(script_segment, regular) {
    let script = "script";
    if (script_segment === "name") {
        script = "script";
    }
    else if (script_segment === "name2") {
        script = "script2";
    }
    else if (script_segment === "name3") {
        script = "script3";
    }
    return [
        ["coalesce", ["get", `pgf:${script_segment}`], ["get", script_segment]],
        {
            "text-font": [
                "case",
                ["==", ["get", script], "Devanagari"],
                ["literal", ["Noto Sans Devanagari Regular v1"]],
                ["literal", [regular || "Noto Sans Regular"]],
            ],
        },
    ];
}
function is_not_in_target_script(lang, script, script_segment) {
    let suffix = "name";
    if (script_segment === "name") {
        suffix = "";
    }
    else if (script_segment === "name2") {
        suffix = "2";
    }
    else if (script_segment === "name3") {
        suffix = "3";
    }
    if (script === "Latin") {
        return ["has", `script${suffix}`];
    }
    if (lang === "ja") {
        return [
            "all",
            ["!=", ["get", `script${suffix}`], "Han"],
            ["!=", ["get", `script${suffix}`], "Hiragana"],
            ["!=", ["get", `script${suffix}`], "Katakana"],
            ["!=", ["get", `script${suffix}`], "Mixed-Japanese"],
        ];
    }
    return ["!=", ["get", `script${suffix}`], script];
}
function get_font_formatting(script) {
    if (script === "Devanagari") {
        return {
            "text-font": ["literal", ["Noto Sans Devanagari Regular v1"]],
        };
    }
    return {};
}
function get_default_script(lang) {
    const pair = exports.language_script_pairs.find((d) => d.lang === lang);
    return pair === undefined ? "Latin" : pair.script;
}
function get_country_name(lang, script) {
    const _script = script || get_default_script(lang);
    let name_prefix;
    if (_script === "Devanagari") {
        name_prefix = "pgf:";
    }
    else {
        name_prefix = "";
    }
    return [
        "format",
        ["coalesce", ["get", `${name_prefix}name:${lang}`], ["get", "name:en"]],
        get_font_formatting(_script),
    ];
}
function get_multiline_name(lang, script, regular) {
    const _script = script || get_default_script(lang);
    let name_prefix;
    if (_script === "Devanagari") {
        name_prefix = "pgf:";
    }
    else {
        name_prefix = "";
    }
    const result = [
        "case",
        [
            "all",
            ["any", ["has", "name"], ["has", "pgf:name"]],
            ["!", ["any", ["has", "name2"], ["has", "pgf:name2"]]],
            ["!", ["any", ["has", "name3"], ["has", "pgf:name3"]]],
        ],
        [
            "case",
            is_not_in_target_script(lang, _script, "name"),
            [
                "case",
                ["any", ["is-supported-script", ["get", "name"]], ["has", "pgf:name"]],
                [
                    "format",
                    [
                        "coalesce",
                        ["get", `${name_prefix}name:${lang}`],
                        ["get", "name:en"],
                    ],
                    get_font_formatting(_script),
                    "\n",
                    {},
                    [
                        "case",
                        [
                            "all",
                            ["!", ["has", `${name_prefix}name:${lang}`]],
                            ["has", "name:en"],
                            ["!", ["has", "script"]],
                        ],
                        "",
                        ["coalesce", ["get", "pgf:name"], ["get", "name"]],
                    ],
                    {
                        "text-font": [
                            "case",
                            ["==", ["get", "script"], "Devanagari"],
                            ["literal", ["Noto Sans Devanagari Regular v1"]],
                            ["literal", [regular || "Noto Sans Regular"]],
                        ],
                    },
                ],
                ["get", "name:en"],
            ],
            [
                "format",
                [
                    "coalesce",
                    ["get", `${name_prefix}name:${lang}`],
                    ["get", "pgf:name"],
                    ["get", "name"],
                ],
                get_font_formatting(_script),
            ],
        ],
        [
            "all",
            ["any", ["has", "name"], ["has", "pgf:name"]],
            ["any", ["has", "name2"], ["has", "pgf:name2"]],
            ["!", ["any", ["has", "name3"], ["has", "pgf:name3"]]],
        ],
        [
            "case",
            [
                "all",
                is_not_in_target_script(lang, _script, "name"),
                is_not_in_target_script(lang, _script, "name2"),
            ],
            [
                "format",
                ["get", `${name_prefix}name:${lang}`],
                get_font_formatting(_script),
                "\n",
                {},
                ...get_name_block("name"),
                "\n",
                {},
                ...get_name_block("name2"),
            ],
            [
                "case",
                is_not_in_target_script(lang, _script, "name2"),
                [
                    "format",
                    [
                        "coalesce",
                        ["get", `${name_prefix}name:${lang}`],
                        ["get", "pgf:name"],
                        ["get", "name"],
                    ],
                    get_font_formatting(_script),
                    "\n",
                    {},
                    ...get_name_block("name2"),
                ],
                [
                    "format",
                    [
                        "coalesce",
                        ["get", `${name_prefix}name:${lang}`],
                        ["get", "pgf:name2"],
                        ["get", "name2"],
                    ],
                    get_font_formatting(_script),
                    "\n",
                    {},
                    ...get_name_block("name"),
                ],
            ],
        ],
        [
            "case",
            [
                "all",
                is_not_in_target_script(lang, _script, "name"),
                is_not_in_target_script(lang, _script, "name2"),
                is_not_in_target_script(lang, _script, "name3"),
            ],
            [
                "format",
                ["get", `${name_prefix}name:${lang}`],
                get_font_formatting(_script),
                "\n",
                {},
                ...get_name_block("name"),
                "\n",
                {},
                ...get_name_block("name2"),
                "\n",
                {},
                ...get_name_block("name3"),
            ],
            [
                "case",
                ["!", is_not_in_target_script(lang, _script, "name")],
                [
                    "format",
                    [
                        "coalesce",
                        ["get", `${name_prefix}name:${lang}`],
                        ["get", "pgf:name"],
                        ["get", "name"],
                    ],
                    get_font_formatting(_script),
                    "\n",
                    {},
                    ...get_name_block("name2"),
                    "\n",
                    {},
                    ...get_name_block("name3"),
                ],
                ["!", is_not_in_target_script(lang, _script, "name2")],
                [
                    "format",
                    [
                        "coalesce",
                        ["get", `${name_prefix}name:${lang}`],
                        ["get", "pgf:name2"],
                        ["get", "name2"],
                    ],
                    get_font_formatting(_script),
                    "\n",
                    {},
                    ...get_name_block("name"),
                    "\n",
                    {},
                    ...get_name_block("name3"),
                ],
                [
                    "format",
                    [
                        "coalesce",
                        ["get", `${name_prefix}name:${lang}`],
                        ["get", "pgf:name3"],
                        ["get", "name3"],
                    ],
                    get_font_formatting(_script),
                    "\n",
                    {},
                    ...get_name_block("name"),
                    "\n",
                    {},
                    ...get_name_block("name2"),
                ],
            ],
        ],
    ];
    return result;
}
exports.language_script_pairs = [
    {
        lang: "ar",
        full_name: "Arabic",
        script: "Arabic",
    },
    {
        lang: "cs",
        full_name: "Czech",
        script: "Latin",
    },
    {
        lang: "bg",
        full_name: "Bulgarian",
        script: "Cyrillic",
    },
    {
        lang: "da",
        full_name: "Danish",
        script: "Latin",
    },
    {
        lang: "de",
        full_name: "German",
        script: "Latin",
    },
    {
        lang: "el",
        full_name: "Greek",
        script: "Greek",
    },
    {
        lang: "en",
        full_name: "English",
        script: "Latin",
    },
    {
        lang: "es",
        full_name: "Spanish",
        script: "Latin",
    },
    {
        lang: "et",
        full_name: "Estonian",
        script: "Latin",
    },
    {
        lang: "fa",
        full_name: "Persian",
        script: "Arabic",
    },
    {
        lang: "fi",
        full_name: "Finnish",
        script: "Latin",
    },
    {
        lang: "fr",
        full_name: "French",
        script: "Latin",
    },
    {
        lang: "ga",
        full_name: "Irish",
        script: "Latin",
    },
    {
        lang: "he",
        full_name: "Hebrew",
        script: "Hebrew",
    },
    {
        lang: "hi",
        full_name: "Hindi",
        script: "Devanagari",
    },
    {
        lang: "hr",
        full_name: "Croatian",
        script: "Latin",
    },
    {
        lang: "hu",
        full_name: "Hungarian",
        script: "Latin",
    },
    {
        lang: "id",
        full_name: "Indonesian",
        script: "Latin",
    },
    {
        lang: "it",
        full_name: "Italian",
        script: "Latin",
    },
    {
        lang: "ja",
        full_name: "Japanese",
        script: "",
    },
    {
        lang: "ko",
        full_name: "Korean",
        script: "Hangul",
    },
    {
        lang: "lt",
        full_name: "Lithuanian",
        script: "Latin",
    },
    {
        lang: "lv",
        full_name: "Latvian",
        script: "Latin",
    },
    {
        lang: "ne",
        full_name: "Nepali",
        script: "Devanagari",
    },
    {
        lang: "nl",
        full_name: "Dutch",
        script: "Latin",
    },
    {
        lang: "no",
        full_name: "Norwegian",
        script: "Latin",
    },
    {
        lang: "mr",
        full_name: "Marathi",
        script: "Devanagari",
    },
    {
        lang: "mt",
        full_name: "Maltese",
        script: "Latin",
    },
    {
        lang: "pl",
        full_name: "Polish",
        script: "Latin",
    },
    {
        lang: "pt",
        full_name: "Portuguese",
        script: "Latin",
    },
    {
        lang: "ro",
        full_name: "Romanian",
        script: "Latin",
    },
    {
        lang: "ru",
        full_name: "Russian",
        script: "Cyrillic",
    },
    {
        lang: "sk",
        full_name: "Slovak",
        script: "Latin",
    },
    {
        lang: "sl",
        full_name: "Slovenian",
        script: "Latin",
    },
    {
        lang: "sv",
        full_name: "Swedish",
        script: "Latin",
    },
    {
        lang: "tr",
        full_name: "Turkish",
        script: "Latin",
    },
    {
        lang: "uk",
        full_name: "Ukrainian",
        script: "Cyrillic",
    },
    {
        lang: "ur",
        full_name: "Urdu",
        script: "Arabic",
    },
    {
        lang: "vi",
        full_name: "Vietnamese",
        script: "Latin",
    },
    {
        lang: "zh-Hans",
        full_name: "Chinese (Simplified)",
        script: "Han",
    },
    {
        lang: "zh-Hant",
        full_name: "Chinese (Traditional)",
        script: "Han",
    },
];

},
/* color2k/src/adjustHue.ts */
24: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const parseToHsla_1 = __importDefault(__req(36));
const hsla_1 = __importDefault(__req(32));
function adjustHue(color, degrees) {
    const [h, s, l, a] = (0, parseToHsla_1.default)(color);
    return (0, hsla_1.default)(h + degrees, s, l, a);
}
exports.default = adjustHue;

},
/* color2k/src/darken.ts */
25: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const parseToHsla_1 = __importDefault(__req(36));
const hsla_1 = __importDefault(__req(32));
function darken(color, amount) {
    const [hue, saturation, lightness, alpha] = (0, parseToHsla_1.default)(color);
    return (0, hsla_1.default)(hue, saturation, lightness - amount, alpha);
}
exports.default = darken;

},
/* color2k/src/desaturate.ts */
26: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const parseToHsla_1 = __importDefault(__req(36));
const hsla_1 = __importDefault(__req(32));
function desaturate(color, amount) {
    const [h, s, l, a] = (0, parseToHsla_1.default)(color);
    return (0, hsla_1.default)(h, s - amount, l, a);
}
exports.default = desaturate;

},
/* color2k/src/getContrast.ts */
27: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const getLuminance_1 = __importDefault(__req(28));
function getContrast(color1, color2) {
    const luminance1 = (0, getLuminance_1.default)(color1);
    const luminance2 = (0, getLuminance_1.default)(color2);
    return luminance1 > luminance2
        ? (luminance1 + 0.05) / (luminance2 + 0.05)
        : (luminance2 + 0.05) / (luminance1 + 0.05);
}
exports.default = getContrast;

},
/* color2k/src/getLuminance.ts */
28: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const parseToRgba_1 = __importDefault(__req(42));
function getLuminance(color) {
    if (color === 'transparent')
        return 0;
    function f(x) {
        const channel = x / 255;
        return channel <= 0.04045
            ? channel / 12.92
            : Math.pow(((channel + 0.055) / 1.055), 2.4);
    }
    const [r, g, b] = (0, parseToRgba_1.default)(color);
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
}
exports.default = getLuminance;

},
/* color2k/src/getScale.ts */
29: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const mix_1 = __importDefault(__req(34));
const guard_1 = __importDefault(__req(30));
function getScale(...colors) {
    return (n) => {
        const lastIndex = colors.length - 1;
        const lowIndex = (0, guard_1.default)(0, lastIndex, Math.floor(n * lastIndex));
        const highIndex = (0, guard_1.default)(0, lastIndex, Math.ceil(n * lastIndex));
        const color1 = colors[lowIndex];
        const color2 = colors[highIndex];
        const unit = 1 / lastIndex;
        const weight = (n - unit * lowIndex) / unit;
        return (0, mix_1.default)(color1, color2, weight);
    };
}
exports.default = getScale;

},
/* color2k/src/guard.ts */
30: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
function guard(low, high, value) {
    return Math.min(Math.max(low, value), high);
}
exports.default = guard;

},
/* color2k/src/hasBadContrast.ts */
31: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const getContrast_1 = __importDefault(__req(27));
const guidelines = {
    decorative: 1.5,
    readable: 3,
    aa: 4.5,
    aaa: 7,
};
function hasBadContrast(color, standard = 'aa', background = '#fff') {
    return (0, getContrast_1.default)(color, background) < guidelines[standard];
}
exports.default = hasBadContrast;

},
/* color2k/src/hsla.ts */
32: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const guard_1 = __importDefault(__req(30));
function hsla(hue, saturation, lightness, alpha) {
    return `hsla(${(hue % 360).toFixed()}, ${(0, guard_1.default)(0, 100, saturation * 100).toFixed()}%, ${(0, guard_1.default)(0, 100, lightness * 100).toFixed()}%, ${parseFloat((0, guard_1.default)(0, 1, alpha).toFixed(3))})`;
}
exports.default = hsla;

},
/* color2k/src/lighten.ts */
33: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const darken_1 = __importDefault(__req(25));
function lighten(color, amount) {
    return (0, darken_1.default)(color, -amount);
}
exports.default = lighten;

},
/* color2k/src/mix.ts */
34: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const parseToRgba_1 = __importDefault(__req(42));
const rgba_1 = __importDefault(__req(39));
function mix(color1, color2, weight) {
    const normalize = (n, index) => index === 3 ? n : n / 255;
    const [r1, g1, b1, a1] = (0, parseToRgba_1.default)(color1).map(normalize);
    const [r2, g2, b2, a2] = (0, parseToRgba_1.default)(color2).map(normalize);
    const alphaDelta = a2 - a1;
    const normalizedWeight = weight * 2 - 1;
    const combinedWeight = normalizedWeight * alphaDelta === -1
        ? normalizedWeight
        : normalizedWeight + alphaDelta / (1 + normalizedWeight * alphaDelta);
    const weight2 = (combinedWeight + 1) / 2;
    const weight1 = 1 - weight2;
    const r = (r1 * weight1 + r2 * weight2) * 255;
    const g = (g1 * weight1 + g2 * weight2) * 255;
    const b = (b1 * weight1 + b2 * weight2) * 255;
    const a = a2 * weight + a1 * (1 - weight);
    return (0, rgba_1.default)(r, g, b, a);
}
exports.default = mix;

},
/* color2k/src/opacify.ts */
35: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const transparentize_1 = __importDefault(__req(41));
function opacify(color, amount) {
    return (0, transparentize_1.default)(color, -amount);
}
exports.default = opacify;

},
/* color2k/src/parseToHsla.ts */
36: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const parseToRgba_1 = __importDefault(__req(42));
function parseToHsla(color) {
    const [red, green, blue, alpha] = (0, parseToRgba_1.default)(color).map((value, index) => index === 3 ? value : value / 255);
    const max = Math.max(red, green, blue);
    const min = Math.min(red, green, blue);
    const lightness = (max + min) / 2;
    if (max === min)
        return [0, 0, lightness, alpha];
    const delta = max - min;
    const saturation = lightness > 0.5 ? delta / (2 - max - min) : delta / (max + min);
    const hue = 60 *
        (red === max
            ? (green - blue) / delta + (green < blue ? 6 : 0)
            : green === max
                ? (blue - red) / delta + 2
                : (red - green) / delta + 4);
    return [hue, saturation, lightness, alpha];
}
exports.default = parseToHsla;

},
/* color2k/src/readableColor.ts */
37: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const readableColorIsBlack_1 = __importDefault(__req(38));
function readableColor(color) {
    return (0, readableColorIsBlack_1.default)(color) ? '#000' : '#fff';
}
exports.default = readableColor;

},
/* color2k/src/readableColorIsBlack.ts */
38: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const getLuminance_1 = __importDefault(__req(28));
function readableColorIsBlack(color) {
    return (0, getLuminance_1.default)(color) > 0.179;
}
exports.default = readableColorIsBlack;

},
/* color2k/src/rgba.ts */
39: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const guard_1 = __importDefault(__req(30));
function rgba(red, green, blue, alpha) {
    return `rgba(${(0, guard_1.default)(0, 255, red).toFixed()}, ${(0, guard_1.default)(0, 255, green).toFixed()}, ${(0, guard_1.default)(0, 255, blue).toFixed()}, ${parseFloat((0, guard_1.default)(0, 1, alpha).toFixed(3))})`;
}
exports.default = rgba;

},
/* color2k/src/saturate.ts */
40: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const desaturate_1 = __importDefault(__req(26));
function saturate(color, amount) {
    return (0, desaturate_1.default)(color, -amount);
}
exports.default = saturate;

},
/* color2k/src/transparentize.ts */
41: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const parseToRgba_1 = __importDefault(__req(42));
const rgba_1 = __importDefault(__req(39));
function transparentize(color, amount) {
    const [r, g, b, a] = (0, parseToRgba_1.default)(color);
    return (0, rgba_1.default)(r, g, b, a - amount);
}
exports.default = transparentize;

},
/* color2k/src/parseToRgba.ts */
42: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const guard_1 = __importDefault(__req(30));
const ColorError_1 = __importDefault(__req(43));
function parseToRgba(color) {
    if (typeof color !== 'string')
        throw new ColorError_1.default(color);
    if (color.trim().toLowerCase() === 'transparent')
        return [0, 0, 0, 0];
    let normalizedColor = color.trim();
    normalizedColor = namedColorRegex.test(color) ? nameToHex(color) : color;
    const reducedHexMatch = reducedHexRegex.exec(normalizedColor);
    if (reducedHexMatch) {
        const arr = Array.from(reducedHexMatch).slice(1);
        return [
            ...arr.slice(0, 3).map((x) => parseInt(r(x, 2), 16)),
            parseInt(r(arr[3] || 'f', 2), 16) / 255,
        ];
    }
    const hexMatch = hexRegex.exec(normalizedColor);
    if (hexMatch) {
        const arr = Array.from(hexMatch).slice(1);
        return [
            ...arr.slice(0, 3).map((x) => parseInt(x, 16)),
            parseInt(arr[3] || 'ff', 16) / 255,
        ];
    }
    const rgbaMatch = rgbaRegex.exec(normalizedColor);
    if (rgbaMatch) {
        const arr = Array.from(rgbaMatch).slice(1);
        return [
            ...arr.slice(0, 3).map((x) => parseInt(x, 10)),
            parseFloat(arr[3] || '1'),
        ];
    }
    const hslaMatch = hslaRegex.exec(normalizedColor);
    if (hslaMatch) {
        const [h, s, l, a] = Array.from(hslaMatch).slice(1).map(parseFloat);
        if ((0, guard_1.default)(0, 100, s) !== s)
            throw new ColorError_1.default(color);
        if ((0, guard_1.default)(0, 100, l) !== l)
            throw new ColorError_1.default(color);
        return [...hslToRgb(h, s, l), Number.isNaN(a) ? 1 : a];
    }
    throw new ColorError_1.default(color);
}
function hash(str) {
    let hash = 5381;
    let i = str.length;
    while (i) {
        hash = (hash * 33) ^ str.charCodeAt(--i);
    }
    return (hash >>> 0) % 2341;
}
const colorToInt = (x) => parseInt(x.replace(/_/g, ''), 36);
const compressedColorMap = '1q29ehhb 1n09sgk7 1kl1ekf_ _yl4zsno 16z9eiv3 1p29lhp8 _bd9zg04 17u0____ _iw9zhe5 _to73___ _r45e31e _7l6g016 _jh8ouiv _zn3qba8 1jy4zshs 11u87k0u 1ro9yvyo 1aj3xael 1gz9zjz0 _3w8l4xo 1bf1ekf_ _ke3v___ _4rrkb__ 13j776yz _646mbhl _nrjr4__ _le6mbhl 1n37ehkb _m75f91n _qj3bzfz 1939yygw 11i5z6x8 _1k5f8xs 1509441m 15t5lwgf _ae2th1n _tg1ugcv 1lp1ugcv 16e14up_ _h55rw7n _ny9yavn _7a11xb_ 1ih442g9 _pv442g9 1mv16xof 14e6y7tu 1oo9zkds 17d1cisi _4v9y70f _y98m8kc 1019pq0v 12o9zda8 _348j4f4 1et50i2o _8epa8__ _ts6senj 1o350i2o 1mi9eiuo 1259yrp0 1ln80gnw _632xcoy 1cn9zldc _f29edu4 1n490c8q _9f9ziet 1b94vk74 _m49zkct 1kz6s73a 1eu9dtog _q58s1rz 1dy9sjiq __u89jo3 _aj5nkwg _ld89jo3 13h9z6wx _qa9z2ii _l119xgq _bs5arju 1hj4nwk9 1qt4nwk9 1ge6wau6 14j9zlcw 11p1edc_ _ms1zcxe _439shk6 _jt9y70f _754zsow 1la40eju _oq5p___ _x279qkz 1fa5r3rv _yd2d9ip _424tcku _8y1di2_ _zi2uabw _yy7rn9h 12yz980_ __39ljp6 1b59zg0x _n39zfzp 1fy9zest _b33k___ _hp9wq92 1il50hz4 _io472ub _lj9z3eo 19z9ykg0 _8t8iu3a 12b9bl4a 1ak5yw0o _896v4ku _tb8k8lv _s59zi6t _c09ze0p 1lg80oqn 1id9z8wb _238nba5 1kq6wgdi _154zssg _tn3zk49 _da9y6tc 1sg7cv4f _r12jvtt 1gq5fmkz 1cs9rvci _lp9jn1c _xw1tdnb 13f9zje6 16f6973h _vo7ir40 _bt5arjf _rc45e4t _hr4e100 10v4e100 _hc9zke2 _w91egv_ _sj2r1kk 13c87yx8 _vqpds__ _ni8ggk8 _tj9yqfb 1ia2j4r4 _7x9b10u 1fc9ld4j 1eq9zldr _5j9lhpx _ez9zl6o _md61fzm'
    .split(' ')
    .reduce((acc, next) => {
    const key = colorToInt(next.substring(0, 3));
    const hex = colorToInt(next.substring(3)).toString(16);
    let prefix = '';
    for (let i = 0; i < 6 - hex.length; i++) {
        prefix += '0';
    }
    acc[key] = `${prefix}${hex}`;
    return acc;
}, {});
function nameToHex(color) {
    const normalizedColorName = color.toLowerCase().trim();
    const result = compressedColorMap[hash(normalizedColorName)];
    if (!result)
        throw new ColorError_1.default(color);
    return `#${result}`;
}
const r = (str, amount) => Array.from(Array(amount))
    .map(() => str)
    .join('');
const reducedHexRegex = new RegExp(`^#${r('([a-f0-9])', 3)}([a-f0-9])?$`, 'i');
const hexRegex = new RegExp(`^#${r('([a-f0-9]{2})', 3)}([a-f0-9]{2})?$`, 'i');
const rgbaRegex = new RegExp(`^rgba?\\(\\s*(\\d+)\\s*${r(',\\s*(\\d+)\\s*', 2)}(?:,\\s*([\\d.]+))?\\s*\\)$`, 'i');
const hslaRegex = /^hsla?\(\s*([\d.]+)\s*,\s*([\d.]+)%\s*,\s*([\d.]+)%(?:\s*,\s*([\d.]+))?\s*\)$/i;
const namedColorRegex = /^[a-z]+$/i;
const roundColor = (color) => {
    return Math.round(color * 255);
};
const hslToRgb = (hue, saturation, lightness) => {
    let l = lightness / 100;
    if (saturation === 0) {
        return [l, l, l].map(roundColor);
    }
    const huePrime = (((hue % 360) + 360) % 360) / 60;
    const chroma = (1 - Math.abs(2 * l - 1)) * (saturation / 100);
    const secondComponent = chroma * (1 - Math.abs((huePrime % 2) - 1));
    let red = 0;
    let green = 0;
    let blue = 0;
    if (huePrime >= 0 && huePrime < 1) {
        red = chroma;
        green = secondComponent;
    }
    else if (huePrime >= 1 && huePrime < 2) {
        red = secondComponent;
        green = chroma;
    }
    else if (huePrime >= 2 && huePrime < 3) {
        green = chroma;
        blue = secondComponent;
    }
    else if (huePrime >= 3 && huePrime < 4) {
        green = secondComponent;
        blue = chroma;
    }
    else if (huePrime >= 4 && huePrime < 5) {
        red = secondComponent;
        blue = chroma;
    }
    else if (huePrime >= 5 && huePrime < 6) {
        red = chroma;
        blue = secondComponent;
    }
    const lightnessModification = l - chroma / 2;
    const finalRed = red + lightnessModification;
    const finalGreen = green + lightnessModification;
    const finalBlue = blue + lightnessModification;
    return [finalRed, finalGreen, finalBlue].map(roundColor);
};
exports.default = parseToRgba;

},
/* color2k/src/ColorError.ts */
43: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
class ColorError extends Error {
    constructor(color) {
        super(`Failed to parse color: "${color}"`);
    }
}
exports.default = ColorError;

},
/* color2k/src/toHex.ts */
44: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const parseToRgba_1 = __importDefault(__req(42));
const guard_1 = __importDefault(__req(30));
function toHex(color) {
    const [r, g, b, a] = (0, parseToRgba_1.default)(color);
    let hex = (x) => {
        const h = (0, guard_1.default)(0, 255, x).toString(16);
        return h.length === 1 ? `0${h}` : h;
    };
    return `#${hex(r)}${hex(g)}${hex(b)}${a < 1 ? hex(Math.round(a * 255)) : ''}`;
}
exports.default = toHex;

},
/* color2k/src/toRgba.ts */
45: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const parseToRgba_1 = __importDefault(__req(42));
const rgba_1 = __importDefault(__req(39));
function toRgba(color) {
    return (0, rgba_1.default)(...(0, parseToRgba_1.default)(color));
}
exports.default = toRgba;

},
/* color2k/src/toHsla.ts */
46: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const parseToHsla_1 = __importDefault(__req(36));
const hsla_1 = __importDefault(__req(32));
function toHsla(color) {
    return (0, hsla_1.default)(...(0, parseToHsla_1.default)(color));
}
exports.default = toHsla;

},
/* fflate/src/index.ts */
47: function (module, exports, __req) {
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.Unzip = exports.AsyncUnzipInflate = exports.UnzipInflate = exports.UnzipPassThrough = exports.Zip = exports.AsyncZipDeflate = exports.ZipDeflate = exports.ZipPassThrough = exports.EncodeUTF8 = exports.DecodeUTF8 = exports.AsyncDecompress = exports.Decompress = exports.Compress = exports.AsyncCompress = exports.AsyncUnzlib = exports.Unzlib = exports.AsyncZlib = exports.Zlib = exports.AsyncGunzip = exports.Gunzip = exports.AsyncGzip = exports.Gzip = exports.AsyncInflate = exports.Inflate = exports.AsyncDeflate = exports.Deflate = exports.FlateErrorCode = void 0;
exports.deflate = deflate;
exports.deflateSync = deflateSync;
exports.inflate = inflate;
exports.inflateSync = inflateSync;
exports.gzip = gzip;
exports.compress = gzip;
exports.gzipSync = gzipSync;
exports.compressSync = gzipSync;
exports.gunzip = gunzip;
exports.gunzipSync = gunzipSync;
exports.zlib = zlib;
exports.zlibSync = zlibSync;
exports.unzlib = unzlib;
exports.unzlibSync = unzlibSync;
exports.gzip = gzip;
exports.compress = gzip;
exports.decompress = decompress;
exports.decompressSync = decompressSync;
exports.strToU8 = strToU8;
exports.strFromU8 = strFromU8;
exports.zip = zip;
exports.zipSync = zipSync;
exports.unzip = unzip;
exports.unzipSync = unzipSync;
const node_worker_1 = __importDefault(__req(51));
const u8 = Uint8Array, u16 = Uint16Array, i32 = Int32Array;
const fleb = new u8([0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 4, 5, 5, 5, 5, 0, 0, 0, 0]);
const fdeb = new u8([0, 0, 0, 0, 1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8, 8, 9, 9, 10, 10, 11, 11, 12, 12, 13, 13, 0, 0]);
const clim = new u8([16, 17, 18, 0, 8, 7, 9, 6, 10, 5, 11, 4, 12, 3, 13, 2, 14, 1, 15]);
const freb = (eb, start) => {
    const b = new u16(31);
    for (let i = 0; i < 31; ++i) {
        b[i] = start += 1 << eb[i - 1];
    }
    const r = new i32(b[30]);
    for (let i = 1; i < 30; ++i) {
        for (let j = b[i]; j < b[i + 1]; ++j) {
            r[j] = ((j - b[i]) << 5) | i;
        }
    }
    return { b, r };
};
const { b: fl, r: revfl } = freb(fleb, 2);
fl[28] = 258, revfl[258] = 28;
const { b: fd, r: revfd } = freb(fdeb, 0);
const rev = new u16(32768);
for (let i = 0; i < 32768; ++i) {
    let x = ((i & 0xAAAA) >> 1) | ((i & 0x5555) << 1);
    x = ((x & 0xCCCC) >> 2) | ((x & 0x3333) << 2);
    x = ((x & 0xF0F0) >> 4) | ((x & 0x0F0F) << 4);
    rev[i] = (((x & 0xFF00) >> 8) | ((x & 0x00FF) << 8)) >> 1;
}
const hMap = ((cd, mb, r) => {
    const s = cd.length;
    let i = 0;
    const l = new u16(mb);
    for (; i < s; ++i) {
        if (cd[i])
            ++l[cd[i] - 1];
    }
    const le = new u16(mb);
    for (i = 1; i < mb; ++i) {
        le[i] = (le[i - 1] + l[i - 1]) << 1;
    }
    let co;
    if (r) {
        co = new u16(1 << mb);
        const rvb = 15 - mb;
        for (i = 0; i < s; ++i) {
            if (cd[i]) {
                const sv = (i << 4) | cd[i];
                const r = mb - cd[i];
                let v = le[cd[i] - 1]++ << r;
                for (const m = v | ((1 << r) - 1); v <= m; ++v) {
                    co[rev[v] >> rvb] = sv;
                }
            }
        }
    }
    else {
        co = new u16(s);
        for (i = 0; i < s; ++i) {
            if (cd[i]) {
                co[i] = rev[le[cd[i] - 1]++] >> (15 - cd[i]);
            }
        }
    }
    return co;
});
const flt = new u8(288);
for (let i = 0; i < 144; ++i)
    flt[i] = 8;
for (let i = 144; i < 256; ++i)
    flt[i] = 9;
for (let i = 256; i < 280; ++i)
    flt[i] = 7;
for (let i = 280; i < 288; ++i)
    flt[i] = 8;
const fdt = new u8(32);
for (let i = 0; i < 32; ++i)
    fdt[i] = 5;
const flm = hMap(flt, 9, 0), flrm = hMap(flt, 9, 1);
const fdm = hMap(fdt, 5, 0), fdrm = hMap(fdt, 5, 1);
const max = (a) => {
    let m = a[0];
    for (let i = 1; i < a.length; ++i) {
        if (a[i] > m)
            m = a[i];
    }
    return m;
};
const bits = (d, p, m) => {
    const o = (p / 8) | 0;
    return ((d[o] | (d[o + 1] << 8)) >> (p & 7)) & m;
};
const bits16 = (d, p) => {
    const o = (p / 8) | 0;
    return ((d[o] | (d[o + 1] << 8) | (d[o + 2] << 16)) >> (p & 7));
};
const shft = (p) => ((p + 7) / 8) | 0;
const slc = (v, s, e) => {
    if (s == null || s < 0)
        s = 0;
    if (e == null || e > v.length)
        e = v.length;
    return new u8(v.subarray(s, e));
};
exports.FlateErrorCode = {
    UnexpectedEOF: 0,
    InvalidBlockType: 1,
    InvalidLengthLiteral: 2,
    InvalidDistance: 3,
    StreamFinished: 4,
    NoStreamHandler: 5,
    InvalidHeader: 6,
    NoCallback: 7,
    InvalidUTF8: 8,
    ExtraFieldTooLong: 9,
    InvalidDate: 10,
    FilenameTooLong: 11,
    StreamFinishing: 12,
    InvalidZipData: 13,
    UnknownCompressionMethod: 14
};
const ec = [
    'unexpected EOF',
    'invalid block type',
    'invalid length/literal',
    'invalid distance',
    'stream finished',
    'no stream handler',
    ,
    'no callback',
    'invalid UTF-8 data',
    'extra field too long',
    'date not in range 1980-2099',
    'filename too long',
    'stream finishing',
    'invalid zip data'
];
;
const err = (ind, msg, nt) => {
    const e = new Error(msg || ec[ind]);
    e.code = ind;
    if (Error.captureStackTrace)
        Error.captureStackTrace(e, err);
    if (!nt)
        throw e;
    return e;
};
const inflt = (dat, st, buf, dict) => {
    const sl = dat.length, dl = dict ? dict.length : 0;
    if (!sl || st.f && !st.l)
        return buf || new u8(0);
    const noBuf = !buf;
    const resize = noBuf || st.i != 2;
    const noSt = st.i;
    if (noBuf)
        buf = new u8(sl * 3);
    const cbuf = (l) => {
        let bl = buf.length;
        if (l > bl) {
            const nbuf = new u8(Math.max(bl * 2, l));
            nbuf.set(buf);
            buf = nbuf;
        }
    };
    let final = st.f || 0, pos = st.p || 0, bt = st.b || 0, lm = st.l, dm = st.d, lbt = st.m, dbt = st.n;
    const tbts = sl * 8;
    do {
        if (!lm) {
            final = bits(dat, pos, 1);
            const type = bits(dat, pos + 1, 3);
            pos += 3;
            if (!type) {
                const s = shft(pos) + 4, l = dat[s - 4] | (dat[s - 3] << 8), t = s + l;
                if (t > sl) {
                    if (noSt)
                        err(0);
                    break;
                }
                if (resize)
                    cbuf(bt + l);
                buf.set(dat.subarray(s, t), bt);
                st.b = bt += l, st.p = pos = t * 8, st.f = final;
                continue;
            }
            else if (type == 1)
                lm = flrm, dm = fdrm, lbt = 9, dbt = 5;
            else if (type == 2) {
                const hLit = bits(dat, pos, 31) + 257, hcLen = bits(dat, pos + 10, 15) + 4;
                const tl = hLit + bits(dat, pos + 5, 31) + 1;
                pos += 14;
                const ldt = new u8(tl);
                const clt = new u8(19);
                for (let i = 0; i < hcLen; ++i) {
                    clt[clim[i]] = bits(dat, pos + i * 3, 7);
                }
                pos += hcLen * 3;
                const clb = max(clt), clbmsk = (1 << clb) - 1;
                const clm = hMap(clt, clb, 1);
                for (let i = 0; i < tl;) {
                    const r = clm[bits(dat, pos, clbmsk)];
                    pos += r & 15;
                    const s = r >> 4;
                    if (s < 16) {
                        ldt[i++] = s;
                    }
                    else {
                        let c = 0, n = 0;
                        if (s == 16)
                            n = 3 + bits(dat, pos, 3), pos += 2, c = ldt[i - 1];
                        else if (s == 17)
                            n = 3 + bits(dat, pos, 7), pos += 3;
                        else if (s == 18)
                            n = 11 + bits(dat, pos, 127), pos += 7;
                        while (n--)
                            ldt[i++] = c;
                    }
                }
                const lt = ldt.subarray(0, hLit), dt = ldt.subarray(hLit);
                lbt = max(lt);
                dbt = max(dt);
                lm = hMap(lt, lbt, 1);
                dm = hMap(dt, dbt, 1);
            }
            else
                err(1);
            if (pos > tbts) {
                if (noSt)
                    err(0);
                break;
            }
        }
        if (resize)
            cbuf(bt + 131072);
        const lms = (1 << lbt) - 1, dms = (1 << dbt) - 1;
        let lpos = pos;
        for (;; lpos = pos) {
            const c = lm[bits16(dat, pos) & lms], sym = c >> 4;
            pos += c & 15;
            if (pos > tbts) {
                if (noSt)
                    err(0);
                break;
            }
            if (!c)
                err(2);
            if (sym < 256)
                buf[bt++] = sym;
            else if (sym == 256) {
                lpos = pos, lm = null;
                break;
            }
            else {
                let add = sym - 254;
                if (sym > 264) {
                    const i = sym - 257, b = fleb[i];
                    add = bits(dat, pos, (1 << b) - 1) + fl[i];
                    pos += b;
                }
                const d = dm[bits16(dat, pos) & dms], dsym = d >> 4;
                if (!d)
                    err(3);
                pos += d & 15;
                let dt = fd[dsym];
                if (dsym > 3) {
                    const b = fdeb[dsym];
                    dt += bits16(dat, pos) & (1 << b) - 1, pos += b;
                }
                if (pos > tbts) {
                    if (noSt)
                        err(0);
                    break;
                }
                if (resize)
                    cbuf(bt + 131072);
                const end = bt + add;
                if (bt < dt) {
                    const shift = dl - dt, dend = Math.min(dt, end);
                    if (shift + bt < 0)
                        err(3);
                    for (; bt < dend; ++bt)
                        buf[bt] = dict[shift + bt];
                }
                for (; bt < end; ++bt)
                    buf[bt] = buf[bt - dt];
            }
        }
        st.l = lm, st.p = lpos, st.b = bt, st.f = final;
        if (lm)
            final = 1, st.m = lbt, st.d = dm, st.n = dbt;
    } while (!final);
    return bt != buf.length && noBuf ? slc(buf, 0, bt) : buf.subarray(0, bt);
};
const wbits = (d, p, v) => {
    v <<= p & 7;
    const o = (p / 8) | 0;
    d[o] |= v;
    d[o + 1] |= v >> 8;
};
const wbits16 = (d, p, v) => {
    v <<= p & 7;
    const o = (p / 8) | 0;
    d[o] |= v;
    d[o + 1] |= v >> 8;
    d[o + 2] |= v >> 16;
};
const hTree = (d, mb) => {
    const t = [];
    for (let i = 0; i < d.length; ++i) {
        if (d[i])
            t.push({ s: i, f: d[i] });
    }
    const s = t.length;
    const t2 = t.slice();
    if (!s)
        return { t: et, l: 0 };
    if (s == 1) {
        const v = new u8(t[0].s + 1);
        v[t[0].s] = 1;
        return { t: v, l: 1 };
    }
    t.sort((a, b) => a.f - b.f);
    t.push({ s: -1, f: 25001 });
    let l = t[0], r = t[1], i0 = 0, i1 = 1, i2 = 2;
    t[0] = { s: -1, f: l.f + r.f, l, r };
    while (i1 != s - 1) {
        l = t[t[i0].f < t[i2].f ? i0++ : i2++];
        r = t[i0 != i1 && t[i0].f < t[i2].f ? i0++ : i2++];
        t[i1++] = { s: -1, f: l.f + r.f, l, r };
    }
    let maxSym = t2[0].s;
    for (let i = 1; i < s; ++i) {
        if (t2[i].s > maxSym)
            maxSym = t2[i].s;
    }
    const tr = new u16(maxSym + 1);
    let mbt = ln(t[i1 - 1], tr, 0);
    if (mbt > mb) {
        let i = 0, dt = 0;
        const lft = mbt - mb, cst = 1 << lft;
        t2.sort((a, b) => tr[b.s] - tr[a.s] || a.f - b.f);
        for (; i < s; ++i) {
            const i2 = t2[i].s;
            if (tr[i2] > mb) {
                dt += cst - (1 << (mbt - tr[i2]));
                tr[i2] = mb;
            }
            else
                break;
        }
        dt >>= lft;
        while (dt > 0) {
            const i2 = t2[i].s;
            if (tr[i2] < mb)
                dt -= 1 << (mb - tr[i2]++ - 1);
            else
                ++i;
        }
        for (; i >= 0 && dt; --i) {
            const i2 = t2[i].s;
            if (tr[i2] == mb) {
                --tr[i2];
                ++dt;
            }
        }
        mbt = mb;
    }
    return { t: new u8(tr), l: mbt };
};
const ln = (n, l, d) => {
    return n.s == -1
        ? Math.max(ln(n.l, l, d + 1), ln(n.r, l, d + 1))
        : (l[n.s] = d);
};
const lc = (c) => {
    let s = c.length;
    while (s && !c[--s])
        ;
    const cl = new u16(++s);
    let cli = 0, cln = c[0], cls = 1;
    const w = (v) => { cl[cli++] = v; };
    for (let i = 1; i <= s; ++i) {
        if (c[i] == cln && i != s)
            ++cls;
        else {
            if (!cln && cls > 2) {
                for (; cls > 138; cls -= 138)
                    w(32754);
                if (cls > 2) {
                    w(cls > 10 ? ((cls - 11) << 5) | 28690 : ((cls - 3) << 5) | 12305);
                    cls = 0;
                }
            }
            else if (cls > 3) {
                w(cln), --cls;
                for (; cls > 6; cls -= 6)
                    w(8304);
                if (cls > 2)
                    w(((cls - 3) << 5) | 8208), cls = 0;
            }
            while (cls--)
                w(cln);
            cls = 1;
            cln = c[i];
        }
    }
    return { c: cl.subarray(0, cli), n: s };
};
const clen = (cf, cl) => {
    let l = 0;
    for (let i = 0; i < cl.length; ++i)
        l += cf[i] * cl[i];
    return l;
};
const wfblk = (out, pos, dat) => {
    const s = dat.length;
    const o = shft(pos + 2);
    out[o] = s & 255;
    out[o + 1] = s >> 8;
    out[o + 2] = out[o] ^ 255;
    out[o + 3] = out[o + 1] ^ 255;
    for (let i = 0; i < s; ++i)
        out[o + i + 4] = dat[i];
    return (o + 4 + s) * 8;
};
const wblk = (dat, out, final, syms, lf, df, eb, li, bs, bl, p) => {
    wbits(out, p++, final);
    ++lf[256];
    const { t: dlt, l: mlb } = hTree(lf, 15);
    const { t: ddt, l: mdb } = hTree(df, 15);
    const { c: lclt, n: nlc } = lc(dlt);
    const { c: lcdt, n: ndc } = lc(ddt);
    const lcfreq = new u16(19);
    for (let i = 0; i < lclt.length; ++i)
        ++lcfreq[lclt[i] & 31];
    for (let i = 0; i < lcdt.length; ++i)
        ++lcfreq[lcdt[i] & 31];
    const { t: lct, l: mlcb } = hTree(lcfreq, 7);
    let nlcc = 19;
    for (; nlcc > 4 && !lct[clim[nlcc - 1]]; --nlcc)
        ;
    const flen = (bl + 5) << 3;
    const ftlen = clen(lf, flt) + clen(df, fdt) + eb;
    const dtlen = clen(lf, dlt) + clen(df, ddt) + eb + 14 + 3 * nlcc + clen(lcfreq, lct) + 2 * lcfreq[16] + 3 * lcfreq[17] + 7 * lcfreq[18];
    if (bs >= 0 && flen <= ftlen && flen <= dtlen)
        return wfblk(out, p, dat.subarray(bs, bs + bl));
    let lm, ll, dm, dl;
    wbits(out, p, 1 + (dtlen < ftlen)), p += 2;
    if (dtlen < ftlen) {
        lm = hMap(dlt, mlb, 0), ll = dlt, dm = hMap(ddt, mdb, 0), dl = ddt;
        const llm = hMap(lct, mlcb, 0);
        wbits(out, p, nlc - 257);
        wbits(out, p + 5, ndc - 1);
        wbits(out, p + 10, nlcc - 4);
        p += 14;
        for (let i = 0; i < nlcc; ++i)
            wbits(out, p + 3 * i, lct[clim[i]]);
        p += 3 * nlcc;
        const lcts = [lclt, lcdt];
        for (let it = 0; it < 2; ++it) {
            const clct = lcts[it];
            for (let i = 0; i < clct.length; ++i) {
                const len = clct[i] & 31;
                wbits(out, p, llm[len]), p += lct[len];
                if (len > 15)
                    wbits(out, p, (clct[i] >> 5) & 127), p += clct[i] >> 12;
            }
        }
    }
    else {
        lm = flm, ll = flt, dm = fdm, dl = fdt;
    }
    for (let i = 0; i < li; ++i) {
        const sym = syms[i];
        if (sym > 255) {
            const len = (sym >> 18) & 31;
            wbits16(out, p, lm[len + 257]), p += ll[len + 257];
            if (len > 7)
                wbits(out, p, (sym >> 23) & 31), p += fleb[len];
            const dst = sym & 31;
            wbits16(out, p, dm[dst]), p += dl[dst];
            if (dst > 3)
                wbits16(out, p, (sym >> 5) & 8191), p += fdeb[dst];
        }
        else {
            wbits16(out, p, lm[sym]), p += ll[sym];
        }
    }
    wbits16(out, p, lm[256]);
    return p + ll[256];
};
const deo = new i32([65540, 131080, 131088, 131104, 262176, 1048704, 1048832, 2114560, 2117632]);
const et = new u8(0);
const dflt = (dat, lvl, plvl, pre, post, st) => {
    const s = st.z || dat.length;
    const o = new u8(pre + s + 5 * (1 + Math.ceil(s / 7000)) + post);
    const w = o.subarray(pre, o.length - post);
    const lst = st.l;
    let pos = (st.r || 0) & 7;
    if (lvl) {
        if (pos)
            w[0] = st.r >> 3;
        const opt = deo[lvl - 1];
        const n = opt >> 13, c = opt & 8191;
        const msk = (1 << plvl) - 1;
        const prev = st.p || new u16(32768), head = st.h || new u16(msk + 1);
        const bs1 = Math.ceil(plvl / 3), bs2 = 2 * bs1;
        const hsh = (i) => (dat[i] ^ (dat[i + 1] << bs1) ^ (dat[i + 2] << bs2)) & msk;
        const syms = new i32(25000);
        const lf = new u16(288), df = new u16(32);
        let lc = 0, eb = 0, i = st.i || 0, li = 0, wi = st.w || 0, bs = 0;
        for (; i + 2 < s; ++i) {
            const hv = hsh(i);
            let imod = i & 32767, pimod = head[hv];
            prev[imod] = pimod;
            head[hv] = imod;
            if (wi <= i) {
                const rem = s - i;
                if ((lc > 7000 || li > 24576) && (rem > 423 || !lst)) {
                    pos = wblk(dat, w, 0, syms, lf, df, eb, li, bs, i - bs, pos);
                    li = lc = eb = 0, bs = i;
                    for (let j = 0; j < 286; ++j)
                        lf[j] = 0;
                    for (let j = 0; j < 30; ++j)
                        df[j] = 0;
                }
                let l = 2, d = 0, ch = c, dif = imod - pimod & 32767;
                if (rem > 2 && hv == hsh(i - dif)) {
                    const maxn = Math.min(n, rem) - 1;
                    const maxd = Math.min(32767, i);
                    const ml = Math.min(258, rem);
                    while (dif <= maxd && --ch && imod != pimod) {
                        if (dat[i + l] == dat[i + l - dif]) {
                            let nl = 0;
                            for (; nl < ml && dat[i + nl] == dat[i + nl - dif]; ++nl)
                                ;
                            if (nl > l) {
                                l = nl, d = dif;
                                if (nl > maxn)
                                    break;
                                const mmd = Math.min(dif, nl - 2);
                                let md = 0;
                                for (let j = 0; j < mmd; ++j) {
                                    const ti = i - dif + j & 32767;
                                    const pti = prev[ti];
                                    const cd = ti - pti & 32767;
                                    if (cd > md)
                                        md = cd, pimod = ti;
                                }
                            }
                        }
                        imod = pimod, pimod = prev[imod];
                        dif += imod - pimod & 32767;
                    }
                }
                if (d) {
                    syms[li++] = 268435456 | (revfl[l] << 18) | revfd[d];
                    const lin = revfl[l] & 31, din = revfd[d] & 31;
                    eb += fleb[lin] + fdeb[din];
                    ++lf[257 + lin];
                    ++df[din];
                    wi = i + l;
                    ++lc;
                }
                else {
                    syms[li++] = dat[i];
                    ++lf[dat[i]];
                }
            }
        }
        for (i = Math.max(i, wi); i < s; ++i) {
            syms[li++] = dat[i];
            ++lf[dat[i]];
        }
        pos = wblk(dat, w, lst, syms, lf, df, eb, li, bs, i - bs, pos);
        if (!lst) {
            st.r = (pos & 7) | w[(pos / 8) | 0] << 3;
            pos -= 7;
            st.h = head, st.p = prev, st.i = i, st.w = wi;
        }
    }
    else {
        for (let i = st.w || 0; i < s + lst; i += 65535) {
            let e = i + 65535;
            if (e >= s) {
                w[(pos / 8) | 0] = lst;
                e = s;
            }
            pos = wfblk(w, pos + 1, dat.subarray(i, e));
        }
        st.i = s;
    }
    return slc(o, 0, pre + shft(pos) + post);
};
const crct = (() => {
    const t = new Int32Array(256);
    for (let i = 0; i < 256; ++i) {
        let c = i, k = 9;
        while (--k)
            c = ((c & 1) && -306674912) ^ (c >>> 1);
        t[i] = c;
    }
    return t;
})();
const crc = () => {
    let c = -1;
    return {
        p(d) {
            let cr = c;
            for (let i = 0; i < d.length; ++i)
                cr = crct[(cr & 255) ^ d[i]] ^ (cr >>> 8);
            c = cr;
        },
        d() { return ~c; }
    };
};
const adler = () => {
    let a = 1, b = 0;
    return {
        p(d) {
            let n = a, m = b;
            const l = d.length | 0;
            for (let i = 0; i != l;) {
                const e = Math.min(i + 2655, l);
                for (; i < e; ++i)
                    m += n += d[i];
                n = (n & 65535) + 15 * (n >> 16), m = (m & 65535) + 15 * (m >> 16);
            }
            a = n, b = m;
        },
        d() {
            a %= 65521, b %= 65521;
            return (a & 255) << 24 | (a & 0xFF00) << 8 | (b & 255) << 8 | (b >> 8);
        }
    };
};
;
const dopt = (dat, opt, pre, post, st) => {
    if (!st) {
        st = { l: 1 };
        if (opt.dictionary) {
            const dict = opt.dictionary.subarray(-32768);
            const newDat = new u8(dict.length + dat.length);
            newDat.set(dict);
            newDat.set(dat, dict.length);
            dat = newDat;
            st.w = dict.length;
        }
    }
    return dflt(dat, opt.level == null ? 6 : opt.level, opt.mem == null ? (st.l ? Math.ceil(Math.max(8, Math.min(13, Math.log(dat.length))) * 1.5) : 20) : (12 + opt.mem), pre, post, st);
};
const mrg = (a, b) => {
    const o = {};
    for (const k in a)
        o[k] = a[k];
    for (const k in b)
        o[k] = b[k];
    return o;
};
const wcln = (fn, fnStr, td) => {
    const dt = fn();
    const st = fn.toString();
    const ks = st.slice(st.indexOf('[') + 1, st.lastIndexOf(']')).replace(/\s+/g, '').split(',');
    for (let i = 0; i < dt.length; ++i) {
        let v = dt[i], k = ks[i];
        if (typeof v == 'function') {
            fnStr += ';' + k + '=';
            const st = v.toString();
            if (v.prototype) {
                if (st.indexOf('[native code]') != -1) {
                    const spInd = st.indexOf(' ', 8) + 1;
                    fnStr += st.slice(spInd, st.indexOf('(', spInd));
                }
                else {
                    fnStr += st;
                    for (const t in v.prototype)
                        fnStr += ';' + k + '.prototype.' + t + '=' + v.prototype[t].toString();
                }
            }
            else
                fnStr += st;
        }
        else
            td[k] = v;
    }
    return fnStr;
};
const ch = [];
const cbfs = (v) => {
    const tl = [];
    for (const k in v) {
        if (v[k].buffer) {
            tl.push((v[k] = new v[k].constructor(v[k])).buffer);
        }
    }
    return tl;
};
const wrkr = (fns, init, id, cb) => {
    if (!ch[id]) {
        let fnStr = '', td = {}, m = fns.length - 1;
        for (let i = 0; i < m; ++i)
            fnStr = wcln(fns[i], fnStr, td);
        ch[id] = { c: wcln(fns[m], fnStr, td), e: td };
    }
    const td = mrg({}, ch[id].e);
    return (0, node_worker_1.default)(ch[id].c + ';onmessage=function(e){for(var k in e.data)self[k]=e.data[k];onmessage=' + init.toString() + '}', id, td, cbfs(td), cb);
};
const bInflt = () => [u8, u16, i32, fleb, fdeb, clim, fl, fd, flrm, fdrm, rev, ec, hMap, max, bits, bits16, shft, slc, err, inflt, inflateSync, pbf, gopt];
const bDflt = () => [u8, u16, i32, fleb, fdeb, clim, revfl, revfd, flm, flt, fdm, fdt, rev, deo, et, hMap, wbits, wbits16, hTree, ln, lc, clen, wfblk, wblk, shft, slc, dflt, dopt, deflateSync, pbf];
const gze = () => [gzh, gzhl, wbytes, crc, crct];
const guze = () => [gzs, gzl];
const zle = () => [zlh, wbytes, adler];
const zule = () => [zls];
const pbf = (msg) => postMessage(msg, [msg.buffer]);
const gopt = (o) => o && {
    out: o.size && new u8(o.size),
    dictionary: o.dictionary
};
const cbify = (dat, opts, fns, init, id, cb) => {
    const w = wrkr(fns, init, id, (err, dat) => {
        w.terminate();
        cb(err, dat);
    });
    w.postMessage([dat, opts], opts.consume ? [dat.buffer] : []);
    return () => { w.terminate(); };
};
const astrm = (strm) => {
    strm.ondata = (dat, final) => postMessage([dat, final], [dat.buffer]);
    return (ev) => {
        if (ev.data.length) {
            strm.push(ev.data[0], ev.data[1]);
            postMessage([ev.data[0].length]);
        }
        else
            strm.flush();
    };
};
const astrmify = (fns, strm, opts, init, id, flush, ext) => {
    let t;
    const w = wrkr(fns, init, id, (err, dat) => {
        if (err)
            w.terminate(), strm.ondata.call(strm, err);
        else if (!Array.isArray(dat))
            ext(dat);
        else if (dat.length == 1) {
            strm.queuedSize -= dat[0];
            if (strm.ondrain)
                strm.ondrain(dat[0]);
        }
        else {
            if (dat[1])
                w.terminate();
            strm.ondata.call(strm, err, dat[0], dat[1]);
        }
    });
    w.postMessage(opts);
    strm.queuedSize = 0;
    strm.push = (d, f) => {
        if (!strm.ondata)
            err(5);
        if (t)
            strm.ondata(err(4, 0, 1), null, !!f);
        strm.queuedSize += d.length;
        w.postMessage([d, t = f], [d.buffer]);
    };
    strm.terminate = () => { w.terminate(); };
    if (flush) {
        strm.flush = () => { w.postMessage([]); };
    }
};
const b2 = (d, b) => d[b] | (d[b + 1] << 8);
const b4 = (d, b) => (d[b] | (d[b + 1] << 8) | (d[b + 2] << 16) | (d[b + 3] << 24)) >>> 0;
const b8 = (d, b) => b4(d, b) + (b4(d, b + 4) * 4294967296);
const wbytes = (d, b, v) => {
    for (; v; ++b)
        d[b] = v, v >>>= 8;
};
const gzh = (c, o) => {
    const fn = o.filename;
    c[0] = 31, c[1] = 139, c[2] = 8, c[8] = o.level < 2 ? 4 : o.level == 9 ? 2 : 0, c[9] = 3;
    if (o.mtime != 0)
        wbytes(c, 4, Math.floor(new Date(o.mtime || Date.now()) / 1000));
    if (fn) {
        c[3] = 8;
        for (let i = 0; i <= fn.length; ++i)
            c[i + 10] = fn.charCodeAt(i);
    }
};
const gzs = (d) => {
    if (d[0] != 31 || d[1] != 139 || d[2] != 8)
        err(6, 'invalid gzip data');
    const flg = d[3];
    let st = 10;
    if (flg & 4)
        st += (d[10] | d[11] << 8) + 2;
    for (let zs = (flg >> 3 & 1) + (flg >> 4 & 1); zs > 0; zs -= !d[st++])
        ;
    return st + (flg & 2);
};
const gzl = (d) => {
    const l = d.length;
    return (d[l - 4] | d[l - 3] << 8 | d[l - 2] << 16 | d[l - 1] << 24) >>> 0;
};
const gzhl = (o) => 10 + (o.filename ? o.filename.length + 1 : 0);
const zlh = (c, o) => {
    const lv = o.level, fl = lv == 0 ? 0 : lv < 6 ? 1 : lv == 9 ? 3 : 2;
    c[0] = 120, c[1] = (fl << 6) | (o.dictionary && 32);
    c[1] |= 31 - ((c[0] << 8) | c[1]) % 31;
    if (o.dictionary) {
        const h = adler();
        h.p(o.dictionary);
        wbytes(c, 2, h.d());
    }
};
const zls = (d, dict) => {
    if ((d[0] & 15) != 8 || (d[0] >> 4) > 7 || ((d[0] << 8 | d[1]) % 31))
        err(6, 'invalid zlib data');
    if ((d[1] >> 5 & 1) == +!dict)
        err(6, 'invalid zlib data: ' + (d[1] & 32 ? 'need' : 'unexpected') + ' dictionary');
    return (d[1] >> 3 & 4) + 2;
};
function StrmOpt(opts, cb) {
    if (typeof opts == 'function')
        cb = opts, opts = {};
    this.ondata = cb;
    return opts;
}
class Deflate {
    constructor(opts, cb) {
        if (typeof opts == 'function')
            cb = opts, opts = {};
        this.ondata = cb;
        this.o = opts || {};
        this.s = { l: 0, i: 32768, w: 32768, z: 32768 };
        this.b = new u8(98304);
        if (this.o.dictionary) {
            const dict = this.o.dictionary.subarray(-32768);
            this.b.set(dict, 32768 - dict.length);
            this.s.i = 32768 - dict.length;
        }
    }
    p(c, f) {
        this.ondata(dopt(c, this.o, 0, 0, this.s), f);
    }
    push(chunk, final) {
        if (!this.ondata)
            err(5);
        if (this.s.l)
            err(4);
        const endLen = chunk.length + this.s.z;
        if (endLen > this.b.length) {
            if (endLen > 2 * this.b.length - 32768) {
                const newBuf = new u8(endLen & -32768);
                newBuf.set(this.b.subarray(0, this.s.z));
                this.b = newBuf;
            }
            const split = this.b.length - this.s.z;
            this.b.set(chunk.subarray(0, split), this.s.z);
            this.s.z = this.b.length;
            this.p(this.b, false);
            this.b.set(this.b.subarray(-32768));
            this.b.set(chunk.subarray(split), 32768);
            this.s.z = chunk.length - split + 32768;
            this.s.i = 32766, this.s.w = 32768;
        }
        else {
            this.b.set(chunk, this.s.z);
            this.s.z += chunk.length;
        }
        this.s.l = final & 1;
        if (this.s.z > this.s.w + 8191 || final) {
            this.p(this.b, final || false);
            this.s.w = this.s.i, this.s.i -= 2;
        }
    }
    flush() {
        if (!this.ondata)
            err(5);
        if (this.s.l)
            err(4);
        this.p(this.b, false);
        this.s.w = this.s.i, this.s.i -= 2;
    }
}
exports.Deflate = Deflate;
class AsyncDeflate {
    constructor(opts, cb) {
        astrmify([
            bDflt,
            () => [astrm, Deflate]
        ], this, StrmOpt.call(this, opts, cb), ev => {
            const strm = new Deflate(ev.data);
            onmessage = astrm(strm);
        }, 6, 1);
    }
}
exports.AsyncDeflate = AsyncDeflate;
function deflate(data, opts, cb) {
    if (!cb)
        cb = opts, opts = {};
    if (typeof cb != 'function')
        err(7);
    return cbify(data, opts, [
        bDflt,
    ], ev => pbf(deflateSync(ev.data[0], ev.data[1])), 0, cb);
}
function deflateSync(data, opts) {
    return dopt(data, opts || {}, 0, 0);
}
class Inflate {
    constructor(opts, cb) {
        if (typeof opts == 'function')
            cb = opts, opts = {};
        this.ondata = cb;
        const dict = opts && opts.dictionary && opts.dictionary.subarray(-32768);
        this.s = { i: 0, b: dict ? dict.length : 0 };
        this.o = new u8(32768);
        this.p = new u8(0);
        if (dict)
            this.o.set(dict);
    }
    e(c) {
        if (!this.ondata)
            err(5);
        if (this.d)
            err(4);
        if (!this.p.length)
            this.p = c;
        else if (c.length) {
            const n = new u8(this.p.length + c.length);
            n.set(this.p), n.set(c, this.p.length), this.p = n;
        }
    }
    c(final) {
        this.s.i = +(this.d = final || false);
        const bts = this.s.b;
        const dt = inflt(this.p, this.s, this.o);
        this.ondata(slc(dt, bts, this.s.b), this.d);
        this.o = slc(dt, this.s.b - 32768), this.s.b = this.o.length;
        this.p = slc(this.p, (this.s.p / 8) | 0), this.s.p &= 7;
    }
    push(chunk, final) {
        this.e(chunk), this.c(final);
    }
}
exports.Inflate = Inflate;
class AsyncInflate {
    constructor(opts, cb) {
        astrmify([
            bInflt,
            () => [astrm, Inflate]
        ], this, StrmOpt.call(this, opts, cb), ev => {
            const strm = new Inflate(ev.data);
            onmessage = astrm(strm);
        }, 7, 0);
    }
}
exports.AsyncInflate = AsyncInflate;
function inflate(data, opts, cb) {
    if (!cb)
        cb = opts, opts = {};
    if (typeof cb != 'function')
        err(7);
    return cbify(data, opts, [
        bInflt
    ], ev => pbf(inflateSync(ev.data[0], gopt(ev.data[1]))), 1, cb);
}
function inflateSync(data, opts) {
    return inflt(data, { i: 2 }, opts && opts.out, opts && opts.dictionary);
}
class Gzip {
    constructor(opts, cb) {
        this.c = crc();
        this.l = 0;
        this.v = 1;
        Deflate.call(this, opts, cb);
    }
    push(chunk, final) {
        this.c.p(chunk);
        this.l += chunk.length;
        Deflate.prototype.push.call(this, chunk, final);
    }
    p(c, f) {
        const raw = dopt(c, this.o, this.v && gzhl(this.o), f && 8, this.s);
        if (this.v)
            gzh(raw, this.o), this.v = 0;
        if (f)
            wbytes(raw, raw.length - 8, this.c.d()), wbytes(raw, raw.length - 4, this.l);
        this.ondata(raw, f);
    }
    flush() {
        Deflate.prototype.flush.call(this);
    }
}
exports.Gzip = Gzip;
exports.Compress = Gzip;
class AsyncGzip {
    constructor(opts, cb) {
        astrmify([
            bDflt,
            gze,
            () => [astrm, Deflate, Gzip]
        ], this, StrmOpt.call(this, opts, cb), ev => {
            const strm = new Gzip(ev.data);
            onmessage = astrm(strm);
        }, 8, 1);
    }
}
exports.AsyncGzip = AsyncGzip;
exports.AsyncCompress = AsyncGzip;
function gzip(data, opts, cb) {
    if (!cb)
        cb = opts, opts = {};
    if (typeof cb != 'function')
        err(7);
    return cbify(data, opts, [
        bDflt,
        gze,
        () => [gzipSync]
    ], ev => pbf(gzipSync(ev.data[0], ev.data[1])), 2, cb);
}
function gzipSync(data, opts) {
    if (!opts)
        opts = {};
    const c = crc(), l = data.length;
    c.p(data);
    const d = dopt(data, opts, gzhl(opts), 8), s = d.length;
    return gzh(d, opts), wbytes(d, s - 8, c.d()), wbytes(d, s - 4, l), d;
}
class Gunzip {
    constructor(opts, cb) {
        this.v = 1;
        this.r = 0;
        Inflate.call(this, opts, cb);
    }
    push(chunk, final) {
        Inflate.prototype.e.call(this, chunk);
        this.r += chunk.length;
        if (this.v) {
            const p = this.p.subarray(this.v - 1);
            const s = p.length > 3 ? gzs(p) : 4;
            if (s > p.length) {
                if (!final)
                    return;
            }
            else if (this.v > 1 && this.onmember) {
                this.onmember(this.r - p.length);
            }
            this.p = p.subarray(s), this.v = 0;
        }
        Inflate.prototype.c.call(this, final);
        if (this.s.f && !this.s.l && !final) {
            this.v = shft(this.s.p) + 9;
            this.s = { i: 0 };
            this.o = new u8(0);
            this.push(new u8(0), final);
        }
    }
}
exports.Gunzip = Gunzip;
class AsyncGunzip {
    constructor(opts, cb) {
        astrmify([
            bInflt,
            guze,
            () => [astrm, Inflate, Gunzip]
        ], this, StrmOpt.call(this, opts, cb), ev => {
            const strm = new Gunzip(ev.data);
            strm.onmember = (offset) => postMessage(offset);
            onmessage = astrm(strm);
        }, 9, 0, offset => this.onmember && this.onmember(offset));
    }
}
exports.AsyncGunzip = AsyncGunzip;
function gunzip(data, opts, cb) {
    if (!cb)
        cb = opts, opts = {};
    if (typeof cb != 'function')
        err(7);
    return cbify(data, opts, [
        bInflt,
        guze,
        () => [gunzipSync]
    ], ev => pbf(gunzipSync(ev.data[0], ev.data[1])), 3, cb);
}
function gunzipSync(data, opts) {
    const st = gzs(data);
    if (st + 8 > data.length)
        err(6, 'invalid gzip data');
    return inflt(data.subarray(st, -8), { i: 2 }, opts && opts.out || new u8(gzl(data)), opts && opts.dictionary);
}
class Zlib {
    constructor(opts, cb) {
        this.c = adler();
        this.v = 1;
        Deflate.call(this, opts, cb);
    }
    push(chunk, final) {
        this.c.p(chunk);
        Deflate.prototype.push.call(this, chunk, final);
    }
    p(c, f) {
        const raw = dopt(c, this.o, this.v && (this.o.dictionary ? 6 : 2), f && 4, this.s);
        if (this.v)
            zlh(raw, this.o), this.v = 0;
        if (f)
            wbytes(raw, raw.length - 4, this.c.d());
        this.ondata(raw, f);
    }
    flush() {
        Deflate.prototype.flush.call(this);
    }
}
exports.Zlib = Zlib;
class AsyncZlib {
    constructor(opts, cb) {
        astrmify([
            bDflt,
            zle,
            () => [astrm, Deflate, Zlib]
        ], this, StrmOpt.call(this, opts, cb), ev => {
            const strm = new Zlib(ev.data);
            onmessage = astrm(strm);
        }, 10, 1);
    }
}
exports.AsyncZlib = AsyncZlib;
function zlib(data, opts, cb) {
    if (!cb)
        cb = opts, opts = {};
    if (typeof cb != 'function')
        err(7);
    return cbify(data, opts, [
        bDflt,
        zle,
        () => [zlibSync]
    ], ev => pbf(zlibSync(ev.data[0], ev.data[1])), 4, cb);
}
function zlibSync(data, opts) {
    if (!opts)
        opts = {};
    const a = adler();
    a.p(data);
    const d = dopt(data, opts, opts.dictionary ? 6 : 2, 4);
    return zlh(d, opts), wbytes(d, d.length - 4, a.d()), d;
}
class Unzlib {
    constructor(opts, cb) {
        Inflate.call(this, opts, cb);
        this.v = opts && opts.dictionary ? 2 : 1;
    }
    push(chunk, final) {
        Inflate.prototype.e.call(this, chunk);
        if (this.v) {
            if (this.p.length < 6 && !final)
                return;
            this.p = this.p.subarray(zls(this.p, this.v - 1)), this.v = 0;
        }
        if (final) {
            if (this.p.length < 4)
                err(6, 'invalid zlib data');
            this.p = this.p.subarray(0, -4);
        }
        Inflate.prototype.c.call(this, final);
    }
}
exports.Unzlib = Unzlib;
class AsyncUnzlib {
    constructor(opts, cb) {
        astrmify([
            bInflt,
            zule,
            () => [astrm, Inflate, Unzlib]
        ], this, StrmOpt.call(this, opts, cb), ev => {
            const strm = new Unzlib(ev.data);
            onmessage = astrm(strm);
        }, 11, 0);
    }
}
exports.AsyncUnzlib = AsyncUnzlib;
function unzlib(data, opts, cb) {
    if (!cb)
        cb = opts, opts = {};
    if (typeof cb != 'function')
        err(7);
    return cbify(data, opts, [
        bInflt,
        zule,
        () => [unzlibSync]
    ], ev => pbf(unzlibSync(ev.data[0], gopt(ev.data[1]))), 5, cb);
}
function unzlibSync(data, opts) {
    return inflt(data.subarray(zls(data, opts && opts.dictionary), -4), { i: 2 }, opts && opts.out, opts && opts.dictionary);
}
class Decompress {
    constructor(opts, cb) {
        this.o = StrmOpt.call(this, opts, cb) || {};
        this.G = Gunzip;
        this.I = Inflate;
        this.Z = Unzlib;
    }
    i() {
        this.s.ondata = (dat, final) => {
            this.ondata(dat, final);
        };
    }
    push(chunk, final) {
        if (!this.ondata)
            err(5);
        if (!this.s) {
            if (this.p && this.p.length) {
                const n = new u8(this.p.length + chunk.length);
                n.set(this.p), n.set(chunk, this.p.length);
            }
            else
                this.p = chunk;
            if (this.p.length > 2) {
                this.s = (this.p[0] == 31 && this.p[1] == 139 && this.p[2] == 8)
                    ? new this.G(this.o)
                    : ((this.p[0] & 15) != 8 || (this.p[0] >> 4) > 7 || ((this.p[0] << 8 | this.p[1]) % 31))
                        ? new this.I(this.o)
                        : new this.Z(this.o);
                this.i();
                this.s.push(this.p, final);
                this.p = null;
            }
        }
        else
            this.s.push(chunk, final);
    }
}
exports.Decompress = Decompress;
class AsyncDecompress {
    constructor(opts, cb) {
        Decompress.call(this, opts, cb);
        this.queuedSize = 0;
        this.G = AsyncGunzip;
        this.I = AsyncInflate;
        this.Z = AsyncUnzlib;
    }
    i() {
        this.s.ondata = (err, dat, final) => {
            this.ondata(err, dat, final);
        };
        this.s.ondrain = size => {
            this.queuedSize -= size;
            if (this.ondrain)
                this.ondrain(size);
        };
    }
    push(chunk, final) {
        this.queuedSize += chunk.length;
        Decompress.prototype.push.call(this, chunk, final);
    }
}
exports.AsyncDecompress = AsyncDecompress;
function decompress(data, opts, cb) {
    if (!cb)
        cb = opts, opts = {};
    if (typeof cb != 'function')
        err(7);
    return (data[0] == 31 && data[1] == 139 && data[2] == 8)
        ? gunzip(data, opts, cb)
        : ((data[0] & 15) != 8 || (data[0] >> 4) > 7 || ((data[0] << 8 | data[1]) % 31))
            ? inflate(data, opts, cb)
            : unzlib(data, opts, cb);
}
function decompressSync(data, opts) {
    return (data[0] == 31 && data[1] == 139 && data[2] == 8)
        ? gunzipSync(data, opts)
        : ((data[0] & 15) != 8 || (data[0] >> 4) > 7 || ((data[0] << 8 | data[1]) % 31))
            ? inflateSync(data, opts)
            : unzlibSync(data, opts);
}
const fltn = (d, p, t, o) => {
    for (const k in d) {
        let val = d[k], n = p + k, op = o;
        if (Array.isArray(val))
            op = mrg(o, val[1]), val = val[0];
        if (val instanceof u8)
            t[n] = [val, op];
        else {
            t[n += '/'] = [new u8(0), op];
            fltn(val, n, t, o);
        }
    }
};
const te = typeof TextEncoder != 'undefined' && new TextEncoder();
const td = typeof TextDecoder != 'undefined' && new TextDecoder();
let tds = 0;
try {
    td.decode(et, { stream: true });
    tds = 1;
}
catch (e) { }
const dutf8 = (d) => {
    for (let r = '', i = 0;;) {
        let c = d[i++];
        const eb = (c > 127) + (c > 223) + (c > 239);
        if (i + eb > d.length)
            return { s: r, r: slc(d, i - 1) };
        if (!eb)
            r += String.fromCharCode(c);
        else if (eb == 3) {
            c = ((c & 15) << 18 | (d[i++] & 63) << 12 | (d[i++] & 63) << 6 | (d[i++] & 63)) - 65536,
                r += String.fromCharCode(55296 | (c >> 10), 56320 | (c & 1023));
        }
        else if (eb & 1)
            r += String.fromCharCode((c & 31) << 6 | (d[i++] & 63));
        else
            r += String.fromCharCode((c & 15) << 12 | (d[i++] & 63) << 6 | (d[i++] & 63));
    }
};
class DecodeUTF8 {
    constructor(cb) {
        this.ondata = cb;
        if (tds)
            this.t = new TextDecoder();
        else
            this.p = et;
    }
    push(chunk, final) {
        if (!this.ondata)
            err(5);
        final = !!final;
        if (this.t) {
            this.ondata(this.t.decode(chunk, { stream: true }), final);
            if (final) {
                if (this.t.decode().length)
                    err(8);
                this.t = null;
            }
            return;
        }
        if (!this.p)
            err(4);
        const dat = new u8(this.p.length + chunk.length);
        dat.set(this.p);
        dat.set(chunk, this.p.length);
        const { s, r } = dutf8(dat);
        if (final) {
            if (r.length)
                err(8);
            this.p = null;
        }
        else
            this.p = r;
        this.ondata(s, final);
    }
}
exports.DecodeUTF8 = DecodeUTF8;
class EncodeUTF8 {
    constructor(cb) {
        this.ondata = cb;
    }
    push(chunk, final) {
        if (!this.ondata)
            err(5);
        if (this.d)
            err(4);
        this.ondata(strToU8(chunk), this.d = final || false);
    }
}
exports.EncodeUTF8 = EncodeUTF8;
function strToU8(str, latin1) {
    if (latin1) {
        const ar = new u8(str.length);
        for (let i = 0; i < str.length; ++i)
            ar[i] = str.charCodeAt(i);
        return ar;
    }
    if (te)
        return te.encode(str);
    const l = str.length;
    let ar = new u8(str.length + (str.length >> 1));
    let ai = 0;
    const w = (v) => { ar[ai++] = v; };
    for (let i = 0; i < l; ++i) {
        if (ai + 5 > ar.length) {
            const n = new u8(ai + 8 + ((l - i) << 1));
            n.set(ar);
            ar = n;
        }
        let c = str.charCodeAt(i);
        if (c < 128 || latin1)
            w(c);
        else if (c < 2048)
            w(192 | (c >> 6)), w(128 | (c & 63));
        else if (c > 55295 && c < 57344)
            c = 65536 + (c & 1023 << 10) | (str.charCodeAt(++i) & 1023),
                w(240 | (c >> 18)), w(128 | ((c >> 12) & 63)), w(128 | ((c >> 6) & 63)), w(128 | (c & 63));
        else
            w(224 | (c >> 12)), w(128 | ((c >> 6) & 63)), w(128 | (c & 63));
    }
    return slc(ar, 0, ai);
}
function strFromU8(dat, latin1) {
    if (latin1) {
        let r = '';
        for (let i = 0; i < dat.length; i += 16384)
            r += String.fromCharCode.apply(null, dat.subarray(i, i + 16384));
        return r;
    }
    else if (td) {
        return td.decode(dat);
    }
    else {
        const { s, r } = dutf8(dat);
        if (r.length)
            err(8);
        return s;
    }
}
;
const dbf = (l) => l == 1 ? 3 : l < 6 ? 2 : l == 9 ? 1 : 0;
const slzh = (d, b) => b + 30 + b2(d, b + 26) + b2(d, b + 28);
const zh = (d, b, z) => {
    const fnl = b2(d, b + 28), fn = strFromU8(d.subarray(b + 46, b + 46 + fnl), !(b2(d, b + 8) & 2048)), es = b + 46 + fnl, bs = b4(d, b + 20);
    const [sc, su, off] = z && bs == 4294967295 ? z64e(d, es) : [bs, b4(d, b + 24), b4(d, b + 42)];
    return [b2(d, b + 10), sc, su, fn, es + b2(d, b + 30) + b2(d, b + 32), off];
};
const z64e = (d, b) => {
    for (; b2(d, b) != 1; b += 4 + b2(d, b + 2))
        ;
    return [b8(d, b + 12), b8(d, b + 4), b8(d, b + 20)];
};
const exfl = (ex) => {
    let le = 0;
    if (ex) {
        for (const k in ex) {
            const l = ex[k].length;
            if (l > 65535)
                err(9);
            le += l + 4;
        }
    }
    return le;
};
const wzh = (d, b, f, fn, u, c, ce, co) => {
    const fl = fn.length, ex = f.extra, col = co && co.length;
    let exl = exfl(ex);
    wbytes(d, b, ce != null ? 0x2014B50 : 0x4034B50), b += 4;
    if (ce != null)
        d[b++] = 20, d[b++] = f.os;
    d[b] = 20, b += 2;
    d[b++] = (f.flag << 1) | (c < 0 && 8), d[b++] = u && 8;
    d[b++] = f.compression & 255, d[b++] = f.compression >> 8;
    const dt = new Date(f.mtime == null ? Date.now() : f.mtime), y = dt.getFullYear() - 1980;
    if (y < 0 || y > 119)
        err(10);
    wbytes(d, b, (y << 25) | ((dt.getMonth() + 1) << 21) | (dt.getDate() << 16) | (dt.getHours() << 11) | (dt.getMinutes() << 5) | (dt.getSeconds() >> 1)), b += 4;
    if (c != -1) {
        wbytes(d, b, f.crc);
        wbytes(d, b + 4, c < 0 ? -c - 2 : c);
        wbytes(d, b + 8, f.size);
    }
    wbytes(d, b + 12, fl);
    wbytes(d, b + 14, exl), b += 16;
    if (ce != null) {
        wbytes(d, b, col);
        wbytes(d, b + 6, f.attrs);
        wbytes(d, b + 10, ce), b += 14;
    }
    d.set(fn, b);
    b += fl;
    if (exl) {
        for (const k in ex) {
            const exf = ex[k], l = exf.length;
            wbytes(d, b, +k);
            wbytes(d, b + 2, l);
            d.set(exf, b + 4), b += 4 + l;
        }
    }
    if (col)
        d.set(co, b), b += col;
    return b;
};
const wzf = (o, b, c, d, e) => {
    wbytes(o, b, 0x6054B50);
    wbytes(o, b + 8, c);
    wbytes(o, b + 10, c);
    wbytes(o, b + 12, d);
    wbytes(o, b + 16, e);
};
class ZipPassThrough {
    constructor(filename) {
        this.filename = filename;
        this.c = crc();
        this.size = 0;
        this.compression = 0;
    }
    process(chunk, final) {
        this.ondata(null, chunk, final);
    }
    push(chunk, final) {
        if (!this.ondata)
            err(5);
        this.c.p(chunk);
        this.size += chunk.length;
        if (final)
            this.crc = this.c.d();
        this.process(chunk, final || false);
    }
}
exports.ZipPassThrough = ZipPassThrough;
class ZipDeflate {
    constructor(filename, opts) {
        if (!opts)
            opts = {};
        ZipPassThrough.call(this, filename);
        this.d = new Deflate(opts, (dat, final) => {
            this.ondata(null, dat, final);
        });
        this.compression = 8;
        this.flag = dbf(opts.level);
    }
    process(chunk, final) {
        try {
            this.d.push(chunk, final);
        }
        catch (e) {
            this.ondata(e, null, final);
        }
    }
    push(chunk, final) {
        ZipPassThrough.prototype.push.call(this, chunk, final);
    }
}
exports.ZipDeflate = ZipDeflate;
class AsyncZipDeflate {
    constructor(filename, opts) {
        if (!opts)
            opts = {};
        ZipPassThrough.call(this, filename);
        this.d = new AsyncDeflate(opts, (err, dat, final) => {
            this.ondata(err, dat, final);
        });
        this.compression = 8;
        this.flag = dbf(opts.level);
        this.terminate = this.d.terminate;
    }
    process(chunk, final) {
        this.d.push(chunk, final);
    }
    push(chunk, final) {
        ZipPassThrough.prototype.push.call(this, chunk, final);
    }
}
exports.AsyncZipDeflate = AsyncZipDeflate;
class Zip {
    constructor(cb) {
        this.ondata = cb;
        this.u = [];
        this.d = 1;
    }
    add(file) {
        if (!this.ondata)
            err(5);
        if (this.d & 2)
            this.ondata(err(4 + (this.d & 1) * 8, 0, 1), null, false);
        else {
            const f = strToU8(file.filename), fl = f.length;
            const com = file.comment, o = com && strToU8(com);
            const u = fl != file.filename.length || (o && (com.length != o.length));
            const hl = fl + exfl(file.extra) + 30;
            if (fl > 65535)
                this.ondata(err(11, 0, 1), null, false);
            const header = new u8(hl);
            wzh(header, 0, file, f, u, -1);
            let chks = [header];
            const pAll = () => {
                for (const chk of chks)
                    this.ondata(null, chk, false);
                chks = [];
            };
            let tr = this.d;
            this.d = 0;
            const ind = this.u.length;
            const uf = mrg(file, {
                f,
                u,
                o,
                t: () => {
                    if (file.terminate)
                        file.terminate();
                },
                r: () => {
                    pAll();
                    if (tr) {
                        const nxt = this.u[ind + 1];
                        if (nxt)
                            nxt.r();
                        else
                            this.d = 1;
                    }
                    tr = 1;
                }
            });
            let cl = 0;
            file.ondata = (err, dat, final) => {
                if (err) {
                    this.ondata(err, dat, final);
                    this.terminate();
                }
                else {
                    cl += dat.length;
                    chks.push(dat);
                    if (final) {
                        const dd = new u8(16);
                        wbytes(dd, 0, 0x8074B50);
                        wbytes(dd, 4, file.crc);
                        wbytes(dd, 8, cl);
                        wbytes(dd, 12, file.size);
                        chks.push(dd);
                        uf.c = cl, uf.b = hl + cl + 16, uf.crc = file.crc, uf.size = file.size;
                        if (tr)
                            uf.r();
                        tr = 1;
                    }
                    else if (tr)
                        pAll();
                }
            };
            this.u.push(uf);
        }
    }
    end() {
        if (this.d & 2) {
            this.ondata(err(4 + (this.d & 1) * 8, 0, 1), null, true);
            return;
        }
        if (this.d)
            this.e();
        else
            this.u.push({
                r: () => {
                    if (!(this.d & 1))
                        return;
                    this.u.splice(-1, 1);
                    this.e();
                },
                t: () => { }
            });
        this.d = 3;
    }
    e() {
        let bt = 0, l = 0, tl = 0;
        for (const f of this.u)
            tl += 46 + f.f.length + exfl(f.extra) + (f.o ? f.o.length : 0);
        const out = new u8(tl + 22);
        for (const f of this.u) {
            wzh(out, bt, f, f.f, f.u, -f.c - 2, l, f.o);
            bt += 46 + f.f.length + exfl(f.extra) + (f.o ? f.o.length : 0), l += f.b;
        }
        wzf(out, bt, this.u.length, tl, l);
        this.ondata(null, out, true);
        this.d = 2;
    }
    terminate() {
        for (const f of this.u)
            f.t();
        this.d = 2;
    }
}
exports.Zip = Zip;
function zip(data, opts, cb) {
    if (!cb)
        cb = opts, opts = {};
    if (typeof cb != 'function')
        err(7);
    const r = {};
    fltn(data, '', r, opts);
    const k = Object.keys(r);
    let lft = k.length, o = 0, tot = 0;
    const slft = lft, files = new Array(lft);
    const term = [];
    const tAll = () => {
        for (let i = 0; i < term.length; ++i)
            term[i]();
    };
    let cbd = (a, b) => {
        mt(() => { cb(a, b); });
    };
    mt(() => { cbd = cb; });
    const cbf = () => {
        const out = new u8(tot + 22), oe = o, cdl = tot - o;
        tot = 0;
        for (let i = 0; i < slft; ++i) {
            const f = files[i];
            try {
                const l = f.c.length;
                wzh(out, tot, f, f.f, f.u, l);
                const badd = 30 + f.f.length + exfl(f.extra);
                const loc = tot + badd;
                out.set(f.c, loc);
                wzh(out, o, f, f.f, f.u, l, tot, f.m), o += 16 + badd + (f.m ? f.m.length : 0), tot = loc + l;
            }
            catch (e) {
                return cbd(e, null);
            }
        }
        wzf(out, o, files.length, cdl, oe);
        cbd(null, out);
    };
    if (!lft)
        cbf();
    for (let i = 0; i < slft; ++i) {
        const fn = k[i];
        const [file, p] = r[fn];
        const c = crc(), size = file.length;
        c.p(file);
        const f = strToU8(fn), s = f.length;
        const com = p.comment, m = com && strToU8(com), ms = m && m.length;
        const exl = exfl(p.extra);
        const compression = p.level == 0 ? 0 : 8;
        const cbl = (e, d) => {
            if (e) {
                tAll();
                cbd(e, null);
            }
            else {
                const l = d.length;
                files[i] = mrg(p, {
                    size,
                    crc: c.d(),
                    c: d,
                    f,
                    m,
                    u: s != fn.length || (m && (com.length != ms)),
                    compression
                });
                o += 30 + s + exl + l;
                tot += 76 + 2 * (s + exl) + (ms || 0) + l;
                if (!--lft)
                    cbf();
            }
        };
        if (s > 65535)
            cbl(err(11, 0, 1), null);
        if (!compression)
            cbl(null, file);
        else if (size < 160000) {
            try {
                cbl(null, deflateSync(file, p));
            }
            catch (e) {
                cbl(e, null);
            }
        }
        else
            term.push(deflate(file, p, cbl));
    }
    return tAll;
}
function zipSync(data, opts) {
    if (!opts)
        opts = {};
    const r = {};
    const files = [];
    fltn(data, '', r, opts);
    let o = 0;
    let tot = 0;
    for (const fn in r) {
        const [file, p] = r[fn];
        const compression = p.level == 0 ? 0 : 8;
        const f = strToU8(fn), s = f.length;
        const com = p.comment, m = com && strToU8(com), ms = m && m.length;
        const exl = exfl(p.extra);
        if (s > 65535)
            err(11);
        const d = compression ? deflateSync(file, p) : file, l = d.length;
        const c = crc();
        c.p(file);
        files.push(mrg(p, {
            size: file.length,
            crc: c.d(),
            c: d,
            f,
            m,
            u: s != fn.length || (m && (com.length != ms)),
            o,
            compression
        }));
        o += 30 + s + exl + l;
        tot += 76 + 2 * (s + exl) + (ms || 0) + l;
    }
    const out = new u8(tot + 22), oe = o, cdl = tot - o;
    for (let i = 0; i < files.length; ++i) {
        const f = files[i];
        wzh(out, f.o, f, f.f, f.u, f.c.length);
        const badd = 30 + f.f.length + exfl(f.extra);
        out.set(f.c, f.o + badd);
        wzh(out, o, f, f.f, f.u, f.c.length, f.o, f.m), o += 16 + badd + (f.m ? f.m.length : 0);
    }
    wzf(out, o, files.length, cdl, oe);
    return out;
}
class UnzipPassThrough {
    push(data, final) {
        this.ondata(null, data, final);
    }
}
exports.UnzipPassThrough = UnzipPassThrough;
UnzipPassThrough.compression = 0;
class UnzipInflate {
    constructor() {
        this.i = new Inflate((dat, final) => {
            this.ondata(null, dat, final);
        });
    }
    push(data, final) {
        try {
            this.i.push(data, final);
        }
        catch (e) {
            this.ondata(e, null, final);
        }
    }
}
exports.UnzipInflate = UnzipInflate;
UnzipInflate.compression = 8;
class AsyncUnzipInflate {
    constructor(_, sz) {
        if (sz < 320000) {
            this.i = new Inflate((dat, final) => {
                this.ondata(null, dat, final);
            });
        }
        else {
            this.i = new AsyncInflate((err, dat, final) => {
                this.ondata(err, dat, final);
            });
            this.terminate = this.i.terminate;
        }
    }
    push(data, final) {
        if (this.i.terminate)
            data = slc(data, 0);
        this.i.push(data, final);
    }
}
exports.AsyncUnzipInflate = AsyncUnzipInflate;
AsyncUnzipInflate.compression = 8;
class Unzip {
    constructor(cb) {
        this.onfile = cb;
        this.k = [];
        this.o = {
            0: UnzipPassThrough
        };
        this.p = et;
    }
    push(chunk, final) {
        if (!this.onfile)
            err(5);
        if (!this.p)
            err(4);
        if (this.c > 0) {
            const len = Math.min(this.c, chunk.length);
            const toAdd = chunk.subarray(0, len);
            this.c -= len;
            if (this.d)
                this.d.push(toAdd, !this.c);
            else
                this.k[0].push(toAdd);
            chunk = chunk.subarray(len);
            if (chunk.length)
                return this.push(chunk, final);
        }
        else {
            let f = 0, i = 0, is, buf;
            if (!this.p.length)
                buf = chunk;
            else if (!chunk.length)
                buf = this.p;
            else {
                buf = new u8(this.p.length + chunk.length);
                buf.set(this.p), buf.set(chunk, this.p.length);
            }
            const l = buf.length, oc = this.c, add = oc && this.d;
            for (; i < l - 4; ++i) {
                const sig = b4(buf, i);
                if (sig == 0x4034B50) {
                    f = 1, is = i;
                    this.d = null;
                    this.c = 0;
                    const bf = b2(buf, i + 6), cmp = b2(buf, i + 8), u = bf & 2048, dd = bf & 8, fnl = b2(buf, i + 26), es = b2(buf, i + 28);
                    if (l > i + 30 + fnl + es) {
                        const chks = [];
                        this.k.unshift(chks);
                        f = 2;
                        let sc = b4(buf, i + 18), su = b4(buf, i + 22);
                        const fn = strFromU8(buf.subarray(i + 30, i += 30 + fnl), !u);
                        if (sc == 4294967295) {
                            [sc, su] = dd ? [-2] : z64e(buf, i);
                        }
                        else if (dd)
                            sc = -1;
                        i += es;
                        this.c = sc;
                        let d;
                        const file = {
                            name: fn,
                            compression: cmp,
                            start: () => {
                                if (!file.ondata)
                                    err(5);
                                if (!sc)
                                    file.ondata(null, et, true);
                                else {
                                    const ctr = this.o[cmp];
                                    if (!ctr)
                                        file.ondata(err(14, 'unknown compression type ' + cmp, 1), null, false);
                                    d = sc < 0 ? new ctr(fn) : new ctr(fn, sc, su);
                                    d.ondata = (err, dat, final) => { file.ondata(err, dat, final); };
                                    for (const dat of chks)
                                        d.push(dat, false);
                                    if (this.k[0] == chks && this.c)
                                        this.d = d;
                                    else
                                        d.push(et, true);
                                }
                            },
                            terminate: () => {
                                if (d && d.terminate)
                                    d.terminate();
                            }
                        };
                        if (sc >= 0)
                            file.size = sc, file.originalSize = su;
                        this.onfile(file);
                    }
                    break;
                }
                else if (oc) {
                    if (sig == 0x8074B50) {
                        is = i += 12 + (oc == -2 && 8), f = 3, this.c = 0;
                        break;
                    }
                    else if (sig == 0x2014B50) {
                        is = i -= 4, f = 3, this.c = 0;
                        break;
                    }
                }
            }
            this.p = et;
            if (oc < 0) {
                const dat = f ? buf.subarray(0, is - 12 - (oc == -2 && 8) - (b4(buf, is - 16) == 0x8074B50 && 4)) : buf.subarray(0, i);
                if (add)
                    add.push(dat, !!f);
                else
                    this.k[+(f == 2)].push(dat);
            }
            if (f & 2)
                return this.push(buf.subarray(i), final);
            this.p = buf.subarray(i);
        }
        if (final) {
            if (this.c)
                err(13);
            this.p = null;
        }
    }
    register(decoder) {
        this.o[decoder.compression] = decoder;
    }
}
exports.Unzip = Unzip;
const mt = typeof queueMicrotask == 'function' ? queueMicrotask : typeof setTimeout == 'function' ? setTimeout : (fn) => { fn(); };
function unzip(data, opts, cb) {
    if (!cb)
        cb = opts, opts = {};
    if (typeof cb != 'function')
        err(7);
    const term = [];
    const tAll = () => {
        for (let i = 0; i < term.length; ++i)
            term[i]();
    };
    const files = {};
    let cbd = (a, b) => {
        mt(() => { cb(a, b); });
    };
    mt(() => { cbd = cb; });
    let e = data.length - 22;
    for (; b4(data, e) != 0x6054B50; --e) {
        if (!e || data.length - e > 65558) {
            cbd(err(13, 0, 1), null);
            return tAll;
        }
    }
    ;
    let lft = b2(data, e + 8);
    if (lft) {
        let c = lft;
        let o = b4(data, e + 16);
        let z = o == 4294967295 || c == 65535;
        if (z) {
            let ze = b4(data, e - 12);
            z = b4(data, ze) == 0x6064B50;
            if (z) {
                c = lft = b4(data, ze + 32);
                o = b4(data, ze + 48);
            }
        }
        const fltr = opts && opts.filter;
        for (let i = 0; i < c; ++i) {
            const [c, sc, su, fn, no, off] = zh(data, o, z), b = slzh(data, off);
            o = no;
            const cbl = (e, d) => {
                if (e) {
                    tAll();
                    cbd(e, null);
                }
                else {
                    if (d)
                        files[fn] = d;
                    if (!--lft)
                        cbd(null, files);
                }
            };
            if (!fltr || fltr({
                name: fn,
                size: sc,
                originalSize: su,
                compression: c
            })) {
                if (!c)
                    cbl(null, slc(data, b, b + sc));
                else if (c == 8) {
                    const infl = data.subarray(b, b + sc);
                    if (su < 524288 || sc > 0.8 * su) {
                        try {
                            cbl(null, inflateSync(infl, { out: new u8(su) }));
                        }
                        catch (e) {
                            cbl(e, null);
                        }
                    }
                    else
                        term.push(inflate(infl, { size: su }, cbl));
                }
                else
                    cbl(err(14, 'unknown compression type ' + c, 1), null);
            }
            else
                cbl(null, null);
        }
    }
    else
        cbd(null, {});
    return tAll;
}
function unzipSync(data, opts) {
    const files = {};
    let e = data.length - 22;
    for (; b4(data, e) != 0x6054B50; --e) {
        if (!e || data.length - e > 65558)
            err(13);
    }
    ;
    let c = b2(data, e + 8);
    if (!c)
        return {};
    let o = b4(data, e + 16);
    let z = o == 4294967295 || c == 65535;
    if (z) {
        let ze = b4(data, e - 12);
        z = b4(data, ze) == 0x6064B50;
        if (z) {
            c = b4(data, ze + 32);
            o = b4(data, ze + 48);
        }
    }
    const fltr = opts && opts.filter;
    for (let i = 0; i < c; ++i) {
        const [c, sc, su, fn, no, off] = zh(data, o, z), b = slzh(data, off);
        o = no;
        if (!fltr || fltr({
            name: fn,
            size: sc,
            originalSize: su,
            compression: c
        })) {
            if (!c)
                files[fn] = slc(data, b, b + sc);
            else if (c == 8)
                files[fn] = inflateSync(data.subarray(b, b + sc), { out: new u8(su) });
            else
                err(14, 'unknown compression type ' + c);
        }
    }
    return files;
}

},
/* PMTiles/js/v2.ts */
48: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.deriveLeaf = exports.createDirectory = exports.sortDir = exports.parseEntry = exports.queryTile = exports.queryLeafdir = exports.getUint48 = exports.getUint24 = exports.unshift = exports.shift = void 0;
const fflate_1 = __req(47);
const index_1 = __req(19);
const shift = (n, shift) => {
    return n * 2 ** shift;
};
exports.shift = shift;
const unshift = (n, shift) => {
    return Math.floor(n / 2 ** shift);
};
exports.unshift = unshift;
const getUint24 = (view, pos) => {
    return (0, exports.shift)(view.getUint16(pos + 1, true), 8) + view.getUint8(pos);
};
exports.getUint24 = getUint24;
const getUint48 = (view, pos) => {
    return (0, exports.shift)(view.getUint32(pos + 2, true), 16) + view.getUint16(pos, true);
};
exports.getUint48 = getUint48;
const compare = (tz, tx, ty, view, i) => {
    if (tz !== view.getUint8(i))
        return tz - view.getUint8(i);
    const x = (0, exports.getUint24)(view, i + 1);
    if (tx !== x)
        return tx - x;
    const y = (0, exports.getUint24)(view, i + 4);
    if (ty !== y)
        return ty - y;
    return 0;
};
const queryLeafdir = (view, z, x, y) => {
    const offsetLen = queryView(view, z | 0x80, x, y);
    if (offsetLen) {
        return {
            z: z,
            x: x,
            y: y,
            offset: offsetLen[0],
            length: offsetLen[1],
            isDir: true,
        };
    }
    return null;
};
exports.queryLeafdir = queryLeafdir;
const queryTile = (view, z, x, y) => {
    const offsetLen = queryView(view, z, x, y);
    if (offsetLen) {
        return {
            z: z,
            x: x,
            y: y,
            offset: offsetLen[0],
            length: offsetLen[1],
            isDir: false,
        };
    }
    return null;
};
exports.queryTile = queryTile;
const queryView = (view, z, x, y) => {
    let m = 0;
    let n = view.byteLength / 17 - 1;
    while (m <= n) {
        const k = (n + m) >> 1;
        const cmp = compare(z, x, y, view, k * 17);
        if (cmp > 0) {
            m = k + 1;
        }
        else if (cmp < 0) {
            n = k - 1;
        }
        else {
            return [(0, exports.getUint48)(view, k * 17 + 7), view.getUint32(k * 17 + 13, true)];
        }
    }
    return null;
};
const entrySort = (a, b) => {
    if (a.isDir && !b.isDir) {
        return 1;
    }
    if (!a.isDir && b.isDir) {
        return -1;
    }
    if (a.z !== b.z) {
        return a.z - b.z;
    }
    if (a.x !== b.x) {
        return a.x - b.x;
    }
    return a.y - b.y;
};
const parseEntry = (dataview, i) => {
    const zRaw = dataview.getUint8(i * 17);
    const z = zRaw & 127;
    return {
        z: z,
        x: (0, exports.getUint24)(dataview, i * 17 + 1),
        y: (0, exports.getUint24)(dataview, i * 17 + 4),
        offset: (0, exports.getUint48)(dataview, i * 17 + 7),
        length: dataview.getUint32(i * 17 + 13, true),
        isDir: zRaw >> 7 === 1,
    };
};
exports.parseEntry = parseEntry;
const sortDir = (a) => {
    const entries = [];
    const view = new DataView(a);
    for (let i = 0; i < view.byteLength / 17; i++) {
        entries.push((0, exports.parseEntry)(view, i));
    }
    return (0, exports.createDirectory)(entries);
};
exports.sortDir = sortDir;
const createDirectory = (entries) => {
    entries.sort(entrySort);
    const buffer = new ArrayBuffer(17 * entries.length);
    const arr = new Uint8Array(buffer);
    for (let i = 0; i < entries.length; i++) {
        const entry = entries[i];
        let z = entry.z;
        if (entry.isDir)
            z = z | 0x80;
        arr[i * 17] = z;
        arr[i * 17 + 1] = entry.x & 0xff;
        arr[i * 17 + 2] = (entry.x >> 8) & 0xff;
        arr[i * 17 + 3] = (entry.x >> 16) & 0xff;
        arr[i * 17 + 4] = entry.y & 0xff;
        arr[i * 17 + 5] = (entry.y >> 8) & 0xff;
        arr[i * 17 + 6] = (entry.y >> 16) & 0xff;
        arr[i * 17 + 7] = entry.offset & 0xff;
        arr[i * 17 + 8] = (0, exports.unshift)(entry.offset, 8) & 0xff;
        arr[i * 17 + 9] = (0, exports.unshift)(entry.offset, 16) & 0xff;
        arr[i * 17 + 10] = (0, exports.unshift)(entry.offset, 24) & 0xff;
        arr[i * 17 + 11] = (0, exports.unshift)(entry.offset, 32) & 0xff;
        arr[i * 17 + 12] = (0, exports.unshift)(entry.offset, 48) & 0xff;
        arr[i * 17 + 13] = entry.length & 0xff;
        arr[i * 17 + 14] = (entry.length >> 8) & 0xff;
        arr[i * 17 + 15] = (entry.length >> 16) & 0xff;
        arr[i * 17 + 16] = (entry.length >> 24) & 0xff;
    }
    return buffer;
};
exports.createDirectory = createDirectory;
const deriveLeaf = (view, tile) => {
    if (view.byteLength < 17)
        return null;
    const numEntries = view.byteLength / 17;
    const entry = (0, exports.parseEntry)(view, numEntries - 1);
    if (entry.isDir) {
        const leafLevel = entry.z;
        const levelDiff = tile.z - leafLevel;
        const leafX = Math.trunc(tile.x / (1 << levelDiff));
        const leafY = Math.trunc(tile.y / (1 << levelDiff));
        return { z: leafLevel, x: leafX, y: leafY };
    }
    return null;
};
exports.deriveLeaf = deriveLeaf;
async function getHeader(source) {
    const resp = await source.getBytes(0, 512000);
    const dataview = new DataView(resp.data);
    const jsonSize = dataview.getUint32(4, true);
    const rootEntries = dataview.getUint16(8, true);
    const dec = new TextDecoder("utf-8");
    const jsonMetadata = JSON.parse(dec.decode(new DataView(resp.data, 10, jsonSize)));
    let tileCompression = index_1.Compression.Unknown;
    if (jsonMetadata.compression === "gzip") {
        tileCompression = index_1.Compression.Gzip;
    }
    let minzoom = 0;
    if ("minzoom" in jsonMetadata) {
        minzoom = +jsonMetadata.minzoom;
    }
    let maxzoom = 0;
    if ("maxzoom" in jsonMetadata) {
        maxzoom = +jsonMetadata.maxzoom;
    }
    let centerLon = 0;
    let centerLat = 0;
    let centerZoom = 0;
    let minLon = -180.0;
    let minLat = -85.0;
    let maxLon = 180.0;
    let maxLat = 85.0;
    if (jsonMetadata.bounds) {
        const split = jsonMetadata.bounds.split(",");
        minLon = +split[0];
        minLat = +split[1];
        maxLon = +split[2];
        maxLat = +split[3];
    }
    if (jsonMetadata.center) {
        const split = jsonMetadata.center.split(",");
        centerLon = +split[0];
        centerLat = +split[1];
        centerZoom = +split[2];
    }
    const header = {
        specVersion: dataview.getUint16(2, true),
        rootDirectoryOffset: 10 + jsonSize,
        rootDirectoryLength: rootEntries * 17,
        jsonMetadataOffset: 10,
        jsonMetadataLength: jsonSize,
        leafDirectoryOffset: 0,
        leafDirectoryLength: undefined,
        tileDataOffset: 0,
        tileDataLength: undefined,
        numAddressedTiles: 0,
        numTileEntries: 0,
        numTileContents: 0,
        clustered: false,
        internalCompression: index_1.Compression.None,
        tileCompression: tileCompression,
        tileType: index_1.TileType.Mvt,
        minZoom: minzoom,
        maxZoom: maxzoom,
        minLon: minLon,
        minLat: minLat,
        maxLon: maxLon,
        maxLat: maxLat,
        centerZoom: centerZoom,
        centerLon: centerLon,
        centerLat: centerLat,
        etag: resp.etag,
    };
    return header;
}
async function getZxy(header, source, cache, z, x, y, signal) {
    let rootDir = await cache.getArrayBuffer(source, header.rootDirectoryOffset, header.rootDirectoryLength, header);
    if (header.specVersion === 1) {
        rootDir = (0, exports.sortDir)(rootDir);
    }
    const entry = (0, exports.queryTile)(new DataView(rootDir), z, x, y);
    if (entry) {
        const resp = await source.getBytes(entry.offset, entry.length, signal);
        let tileData = resp.data;
        const view = new DataView(tileData);
        if (view.getUint8(0) === 0x1f && view.getUint8(1) === 0x8b) {
            tileData = (0, fflate_1.decompressSync)(new Uint8Array(tileData));
        }
        return {
            data: tileData,
        };
    }
    const leafcoords = (0, exports.deriveLeaf)(new DataView(rootDir), { z: z, x: x, y: y });
    if (leafcoords) {
        const leafdirEntry = (0, exports.queryLeafdir)(new DataView(rootDir), leafcoords.z, leafcoords.x, leafcoords.y);
        if (leafdirEntry) {
            let leafDir = await cache.getArrayBuffer(source, leafdirEntry.offset, leafdirEntry.length, header);
            if (header.specVersion === 1) {
                leafDir = (0, exports.sortDir)(leafDir);
            }
            const tileEntry = (0, exports.queryTile)(new DataView(leafDir), z, x, y);
            if (tileEntry) {
                const resp = await source.getBytes(tileEntry.offset, tileEntry.length, signal);
                let tileData = resp.data;
                const view = new DataView(tileData);
                if (view.getUint8(0) === 0x1f && view.getUint8(1) === 0x8b) {
                    tileData = (0, fflate_1.decompressSync)(new Uint8Array(tileData));
                }
                return {
                    data: tileData,
                };
            }
        }
    }
    return undefined;
}
exports.default = {
    getHeader: getHeader,
    getZxy: getZxy,
};

},
/* PMTiles/js/adapters.ts */
49: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.Protocol = exports.leafletRasterLayer = void 0;
const index_1 = __req(19);
const leafletRasterLayer = (source, options) => {
    let loaded = false;
    let mimeType = "";
    const cls = L.GridLayer.extend({
        createTile: (coord, done) => {
            const el = document.createElement("img");
            const controller = new AbortController();
            const signal = controller.signal;
            el.cancel = () => {
                controller.abort();
            };
            if (!loaded) {
                source.getHeader().then((header) => {
                    if (header.tileType === index_1.TileType.Mvt) {
                        console.error("Error: archive contains MVT vector tiles, but leafletRasterLayer is for displaying raster tiles. See https://github.com/protomaps/PMTiles/tree/main/js for details.");
                    }
                    else if (header.tileType === 2) {
                        mimeType = "image/png";
                    }
                    else if (header.tileType === 3) {
                        mimeType = "image/jpeg";
                    }
                    else if (header.tileType === 4) {
                        mimeType = "image/webp";
                    }
                    else if (header.tileType === 5) {
                        mimeType = "image/avif";
                    }
                });
                loaded = true;
            }
            source
                .getZxy(coord.z, coord.x, coord.y, signal)
                .then((arr) => {
                if (arr) {
                    const blob = new Blob([arr.data], { type: mimeType });
                    const imageUrl = window.URL.createObjectURL(blob);
                    el.src = imageUrl;
                    el.cancel = undefined;
                    done(undefined, el);
                }
            })
                .catch((e) => {
                if (e.name !== "AbortError") {
                    throw e;
                }
            });
            return el;
        },
        _removeTile: function (key) {
            const tile = this._tiles[key];
            if (!tile) {
                return;
            }
            if (tile.el.cancel)
                tile.el.cancel();
            tile.el.width = 0;
            tile.el.height = 0;
            tile.el.deleted = true;
            L.DomUtil.remove(tile.el);
            delete this._tiles[key];
            this.fire("tileunload", {
                tile: tile.el,
                coords: this._keyToTileCoords(key),
            });
        },
    });
    return new cls(options);
};
exports.leafletRasterLayer = leafletRasterLayer;
const v3compat = (v4) => (requestParameters, arg2) => {
    if (arg2 instanceof AbortController) {
        return v4(requestParameters, arg2);
    }
    const abortController = new AbortController();
    v4(requestParameters, abortController)
        .then((result) => {
        return arg2(undefined, result.data, result.cacheControl || "", result.expires || "");
    }, (err) => {
        return arg2(err);
    })
        .catch((e) => {
        return arg2(e);
    });
    return { cancel: () => abortController.abort() };
};
class Protocol {
    constructor(options) {
        this.tilev4 = async (params, abortController) => {
            if (params.type === "json") {
                const pmtilesUrl = params.url.substr(10);
                let instance = this.tiles.get(pmtilesUrl);
                if (!instance) {
                    instance = new index_1.PMTiles(pmtilesUrl);
                    this.tiles.set(pmtilesUrl, instance);
                }
                if (this.metadata) {
                    return {
                        data: await instance.getTileJson(params.url),
                    };
                }
                const h = await instance.getHeader();
                return {
                    data: {
                        tiles: [`${params.url}/{z}/{x}/{y}`],
                        minzoom: h.minZoom,
                        maxzoom: h.maxZoom,
                        bounds: [h.minLon, h.minLat, h.maxLon, h.maxLat],
                    },
                };
            }
            const re = new RegExp(/pmtiles:\/\/(.+)\/(\d+)\/(\d+)\/(\d+)/);
            const result = params.url.match(re);
            if (!result) {
                throw new Error("Invalid PMTiles protocol URL");
            }
            const pmtilesUrl = result[1];
            let instance = this.tiles.get(pmtilesUrl);
            if (!instance) {
                instance = new index_1.PMTiles(pmtilesUrl);
                this.tiles.set(pmtilesUrl, instance);
            }
            const z = result[2];
            const x = result[3];
            const y = result[4];
            const header = await instance.getHeader();
            const resp = await (instance === null || instance === void 0 ? void 0 : instance.getZxy(+z, +x, +y, abortController.signal));
            if (resp) {
                return {
                    data: new Uint8Array(resp.data),
                    cacheControl: resp.cacheControl,
                    expires: resp.expires,
                };
            }
            if (header.tileType === index_1.TileType.Mvt) {
                return { data: new Uint8Array() };
            }
            return { data: null };
        };
        this.tile = v3compat(this.tilev4);
        this.tiles = new Map();
        this.metadata = (options === null || options === void 0 ? void 0 : options.metadata) || false;
    }
    add(p) {
        this.tiles.set(p.source.getKey(), p);
    }
    get(url) {
        return this.tiles.get(url);
    }
}
exports.Protocol = Protocol;

},
/* quickselect/index.js */
50: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.default = quickselect;
function quickselect(arr, k, left, right, compare) {
    quickselectStep(arr, k, left || 0, right || (arr.length - 1), compare || defaultCompare);
}
function quickselectStep(arr, k, left, right, compare) {
    while (right > left) {
        if (right - left > 600) {
            var n = right - left + 1;
            var m = k - left + 1;
            var z = Math.log(n);
            var s = 0.5 * Math.exp(2 * z / 3);
            var sd = 0.5 * Math.sqrt(z * s * (n - s) / n) * (m - n / 2 < 0 ? -1 : 1);
            var newLeft = Math.max(left, Math.floor(k - m * s / n + sd));
            var newRight = Math.min(right, Math.floor(k + (n - m) * s / n + sd));
            quickselectStep(arr, k, newLeft, newRight, compare);
        }
        var t = arr[k];
        var i = left;
        var j = right;
        swap(arr, left, k);
        if (compare(arr[right], t) > 0)
            swap(arr, left, right);
        while (i < j) {
            swap(arr, i, j);
            i++;
            j--;
            while (compare(arr[i], t) < 0)
                i++;
            while (compare(arr[j], t) > 0)
                j--;
        }
        if (compare(arr[left], t) === 0)
            swap(arr, left, j);
        else {
            j++;
            swap(arr, j, right);
        }
        if (j <= k)
            left = j + 1;
        if (k <= j)
            right = j - 1;
    }
}
function swap(arr, i, j) {
    var tmp = arr[i];
    arr[i] = arr[j];
    arr[j] = tmp;
}
function defaultCompare(a, b) {
    return a < b ? -1 : a > b ? 1 : 0;
}

},
/* fflate/src/node-worker.ts */
51: function (module, exports, __req) {
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
let Worker;
const workerAdd = ";var __w=require('worker_threads');__w.parentPort.on('message',function(m){onmessage({data:m})}),postMessage=function(m,t){__w.parentPort.postMessage(m,t)},close=process.exit;self=global";
try {
    Worker = require('worker_threads').Worker;
}
catch (e) {
}
exports.default = Worker ? (c, _, msg, transfer, cb) => {
    let done = false;
    const w = new Worker(c + workerAdd, { eval: true })
        .on('error', e => cb(e, null))
        .on('message', m => cb(null, m))
        .on('exit', c => {
        if (c && !done)
            cb(new Error('exited with code ' + c), null);
    });
    w.postMessage(msg, transfer);
    w.terminate = () => {
        done = true;
        return Worker.prototype.terminate.call(w);
    };
    return w;
} : (_, __, ___, ____, cb) => {
    setImmediate(() => cb(new Error('async operations unsupported - update to Node 12+ (or Node 10-11 with the --experimental-worker CLI flag)'), null));
    const NOP = () => { };
    return {
        terminate: NOP,
        postMessage: NOP
    };
};

}
  };
  var cache = {};
  function __req(id) {
    if (cache[id]) return cache[id].exports;
    var module = (cache[id] = { exports: {} });
    defs[id](module, module.exports, __req);
    return module.exports;
  }
  return __req(0);
})();
