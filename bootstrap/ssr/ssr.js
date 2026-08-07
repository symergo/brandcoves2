import { Head, Link, createInertiaApp, router, usePage } from "@inertiajs/react";
import { Fragment, jsx, jsxs } from "react/jsx-runtime";
import { useState } from "react";
import createServer from "@inertiajs/react/server";
import ReactDOMServer from "react-dom/server";
//#region \0rolldown/runtime.js
var __defProp = Object.defineProperty;
var __exportAll = (all, no_symbols) => {
	let target = {};
	for (var name in all) __defProp(target, name, {
		get: all[name],
		enumerable: true
	});
	if (!no_symbols) __defProp(target, Symbol.toStringTag, { value: "Module" });
	return target;
};
//#endregion
//#region resources/js/useTranslations.ts
/**
* Site copy for the current market's language.
*
* The market decides the catalogue; its language decides the words. `be-nl` and
* `nl-nl` are two markets sharing one set of strings, so translations are keyed
* by language rather than by market.
*/
function useTranslations() {
	const { translations, market } = usePage().props;
	/**
	* Look up a dotted key, e.g. `t('home.cta_gift')`.
	*
	* Returns the key itself when a string is missing, rather than an empty
	* space. A visible `home.cta_gift` in the UI is an obvious bug; a blank
	* button is one you ship without noticing.
	*/
	function t(key, replacements = {}) {
		const value = key.split(".").reduce((carry, part) => {
			if (carry !== null && typeof carry === "object" && part in carry) return carry[part];
		}, translations);
		if (typeof value !== "string") return key;
		return Object.entries(replacements).reduce((carry, [token, replacement]) => carry.replaceAll(`:${token}`, String(replacement)), value);
	}
	/**
	* Numbers follow the MARKET, not the language: nl-BE and nl-NL agree on
	* words and disagree on how a thousands separator is written.
	*/
	function n(value) {
		return new Intl.NumberFormat(market.hrefLang).format(value);
	}
	return {
		t,
		n,
		locale: market.hrefLang,
		language: market.language
	};
}
//#endregion
//#region resources/js/Pages/Home.tsx
var Home_exports = /* @__PURE__ */ __exportAll({ default: () => Home });
function Home({ stats }) {
	const { market } = usePage().props;
	const { t, n } = useTranslations();
	const base = `/${market.key}`;
	return /* @__PURE__ */ jsxs(Fragment, { children: [
		/* @__PURE__ */ jsx(Head, { title: t("home.title") }),
		/* @__PURE__ */ jsxs("section", {
			className: "max-w-2xl",
			children: [
				/* @__PURE__ */ jsxs("h1", {
					className: "text-4xl font-semibold tracking-tight sm:text-5xl",
					children: [
						t("home.headline_1"),
						/* @__PURE__ */ jsx("br", {}),
						t("home.headline_2")
					]
				}),
				/* @__PURE__ */ jsx("p", {
					className: "mt-5 text-lg text-ink-soft",
					children: t("home.intro")
				}),
				/* @__PURE__ */ jsxs("div", {
					className: "mt-8 flex flex-wrap gap-3",
					children: [/* @__PURE__ */ jsx(Link, {
						href: `${base}/gift`,
						className: "rounded-lg bg-accent px-5 py-3 font-medium text-white transition hover:bg-accent-dark",
						children: t("home.cta_gift")
					}), /* @__PURE__ */ jsx(Link, {
						href: `${base}/search`,
						className: "rounded-lg border border-line px-5 py-3 font-medium transition hover:border-ink",
						children: t("home.cta_search")
					})]
				})
			]
		}),
		/* @__PURE__ */ jsxs("section", {
			className: "mt-16 grid gap-4 sm:grid-cols-3",
			"aria-label": t("home.stats_label"),
			children: [
				/* @__PURE__ */ jsx(Stat, {
					label: t("home.stat_products"),
					value: n(stats.products)
				}),
				/* @__PURE__ */ jsx(Stat, {
					label: t("home.stat_comparable"),
					value: n(stats.comparable),
					hint: t("home.stat_comparable_hint")
				}),
				/* @__PURE__ */ jsx(Stat, {
					label: t("home.stat_guides"),
					value: n(stats.guides)
				})
			]
		}),
		stats.products === 0 && /* @__PURE__ */ jsxs("p", {
			className: "mt-6 rounded-card border border-line bg-card p-4 text-sm text-ink-soft",
			children: [
				t("home.empty_catalogue"),
				" ",
				/* @__PURE__ */ jsxs("code", {
					className: "rounded bg-cream px-1.5 py-0.5",
					children: ["php artisan bc:ingest --market=", market.key]
				})
			]
		})
	] });
}
function Stat({ label, value, hint }) {
	return /* @__PURE__ */ jsxs("div", {
		className: "rounded-card border border-line bg-card p-5",
		children: [
			/* @__PURE__ */ jsx("div", {
				className: "text-3xl font-semibold tabular-nums",
				children: value
			}),
			/* @__PURE__ */ jsx("div", {
				className: "mt-1 text-sm text-ink-soft",
				children: label
			}),
			hint && /* @__PURE__ */ jsx("div", {
				className: "mt-0.5 text-xs text-ink-soft/70",
				children: hint
			})
		]
	});
}
//#endregion
//#region resources/js/types.ts
function formatPrice(cents, market) {
	return new Intl.NumberFormat(market.hrefLang, {
		style: "currency",
		currency: market.currency
	}).format(cents / 100);
}
//#endregion
//#region resources/js/Components/Sparkline.tsx
/**
* Daily low across all shops, over 90 days.
*
* Inline SVG rather than a charting library: this is one path and two labels,
* and pulling in 40 KB of chart code for it would cost more than it renders.
*
* The line is the *minimum* across offers, because the question a price chart
* answers is "what would this have cost me", not "what did one particular shop
* charge".
*/
function Sparkline({ points, market }) {
	if (points.length < 2) return null;
	const width = 480;
	const height = 90;
	const pad = 4;
	const prices = points.map((p) => p.price);
	const lo = Math.min(...prices);
	const hi = Math.max(...prices);
	const span = hi - lo || 1;
	const coords = points.map((p, i) => {
		const x = pad + i / (points.length - 1) * 472;
		const y = pad + (1 - (p.price - lo) / span) * 82;
		return `${x.toFixed(1)},${y.toFixed(1)}`;
	});
	const first = points[0];
	const fell = points[points.length - 1].price < first.price;
	return /* @__PURE__ */ jsxs("figure", {
		className: "rounded-card border border-line bg-card p-4",
		children: [/* @__PURE__ */ jsxs("svg", {
			viewBox: `0 0 ${width} ${height}`,
			className: "h-24 w-full",
			role: "img",
			"aria-label": `${formatPrice(lo, market)} – ${formatPrice(hi, market)}`,
			preserveAspectRatio: "none",
			children: [/* @__PURE__ */ jsx("polygon", {
				points: `${pad},86 ${coords.join(" ")} 476,86`,
				fill: fell ? "var(--color-sage)" : "var(--color-accent)",
				opacity: "0.08"
			}), /* @__PURE__ */ jsx("polyline", {
				points: coords.join(" "),
				fill: "none",
				stroke: fell ? "var(--color-sage)" : "var(--color-accent)",
				strokeWidth: "2",
				strokeLinejoin: "round",
				strokeLinecap: "round",
				vectorEffect: "non-scaling-stroke"
			})]
		}), /* @__PURE__ */ jsxs("figcaption", {
			className: "mt-2 flex justify-between text-xs text-ink-soft",
			children: [/* @__PURE__ */ jsx("span", { children: formatPrice(lo, market) }), /* @__PURE__ */ jsx("span", { children: formatPrice(hi, market) })]
		})]
	});
}
//#endregion
//#region resources/js/Pages/Product.tsx
var Product_exports = /* @__PURE__ */ __exportAll({ default: () => Product });
function Product({ product, offers, history }) {
	const { market } = usePage().props;
	const { t, n } = useTranslations();
	const cheapestId = offers.filter((o) => o.isBuyable)[0]?.id;
	return /* @__PURE__ */ jsxs(Fragment, { children: [
		/* @__PURE__ */ jsx(Head, { title: product.title }),
		/* @__PURE__ */ jsxs("div", {
			className: "grid gap-10 lg:grid-cols-2",
			children: [/* @__PURE__ */ jsx("div", {
				className: "rounded-card border border-line bg-card p-8",
				children: product.image && /* @__PURE__ */ jsx("img", {
					src: product.image,
					alt: product.title,
					className: "mx-auto max-h-96 w-full object-contain"
				})
			}), /* @__PURE__ */ jsxs("div", { children: [
				product.brand && /* @__PURE__ */ jsx("div", {
					className: "text-sm tracking-wide text-ink-soft uppercase",
					children: product.brand
				}),
				/* @__PURE__ */ jsx("h1", {
					className: "mt-1 text-2xl font-semibold sm:text-3xl",
					children: product.title
				}),
				product.minPrice !== null && /* @__PURE__ */ jsxs("div", {
					className: "mt-5 flex flex-wrap items-baseline gap-3",
					children: [/* @__PURE__ */ jsx("span", {
						className: "text-3xl font-semibold",
						children: formatPrice(product.minPrice, market)
					}), product.discountPercent !== null && product.medianPrice && /* @__PURE__ */ jsxs(Fragment, { children: [/* @__PURE__ */ jsx("span", {
						className: "rounded bg-accent px-2 py-1 text-sm font-medium text-white",
						children: t("product.off", { percent: product.discountPercent })
					}), /* @__PURE__ */ jsx("span", {
						className: "text-sm text-ink-soft",
						children: t("product.typical_price", { price: formatPrice(product.medianPrice, market) })
					})] })]
				}),
				/* @__PURE__ */ jsx("p", {
					className: "mt-2 text-sm text-ink-soft",
					children: product.merchantCount > 1 ? t("product.compare", { count: n(offers.length) }) : t("product.one_shop")
				}),
				history.length > 2 && /* @__PURE__ */ jsxs("div", {
					className: "mt-8",
					children: [/* @__PURE__ */ jsx("h2", {
						className: "mb-2 text-sm font-medium",
						children: t("product.price_history")
					}), /* @__PURE__ */ jsx(Sparkline, {
						points: history,
						market
					})]
				}),
				product.ean && /* @__PURE__ */ jsxs("p", {
					className: "mt-6 text-xs text-ink-soft",
					children: [
						t("product.barcode"),
						": ",
						/* @__PURE__ */ jsx("code", { children: product.ean })
					]
				})
			] })]
		}),
		/* @__PURE__ */ jsxs("section", {
			className: "mt-12",
			children: [
				/* @__PURE__ */ jsx("h2", {
					className: "mb-4 text-xl font-semibold",
					children: t("product.all_offers")
				}),
				offers.length === 0 ? /* @__PURE__ */ jsx("p", {
					className: "rounded-card border border-line bg-card p-6 text-ink-soft",
					children: t("product.unavailable")
				}) : /* @__PURE__ */ jsx("ul", {
					className: "divide-y divide-line overflow-hidden rounded-card border border-line bg-card",
					children: offers.map((offer) => /* @__PURE__ */ jsxs("li", {
						className: "flex flex-wrap items-center gap-4 p-4",
						children: [
							offer.merchantLogo && /* @__PURE__ */ jsx("img", {
								src: offer.merchantLogo,
								alt: "",
								width: 20,
								height: 20,
								className: "h-5 w-5 rounded",
								onError: (e) => {
									e.currentTarget.style.display = "none";
								}
							}),
							/* @__PURE__ */ jsxs("div", {
								className: "min-w-0 flex-1",
								children: [/* @__PURE__ */ jsx("div", {
									className: "font-medium",
									children: offer.merchant
								}), /* @__PURE__ */ jsx("div", {
									className: "truncate text-xs text-ink-soft",
									children: offer.title
								})]
							}),
							offer.id === cheapestId && /* @__PURE__ */ jsx("span", {
								className: "rounded bg-sage/15 px-2 py-1 text-xs font-medium text-sage",
								children: t("product.cheapest")
							}),
							/* @__PURE__ */ jsxs("div", {
								className: "text-right",
								children: [offer.price !== null && /* @__PURE__ */ jsx("div", {
									className: "text-lg font-semibold",
									children: formatPrice(offer.price, market)
								}), /* @__PURE__ */ jsx("div", {
									className: `text-xs ${offer.isBuyable ? "text-sage" : "text-ink-soft"}`,
									children: offer.isBuyable ? t("product.in_stock") : t("product.out_of_stock")
								})]
							}),
							/* @__PURE__ */ jsx("a", {
								href: offer.url,
								rel: "sponsored noopener nofollow",
								target: "_blank",
								className: `rounded-lg px-4 py-2 text-sm font-medium ${offer.isBuyable ? "bg-accent text-white hover:bg-accent-dark" : "pointer-events-none border border-line text-ink-soft opacity-50"}`,
								children: t("product.go_to_shop")
							})
						]
					}, offer.id))
				}),
				/* @__PURE__ */ jsx("p", {
					className: "mt-3 text-xs text-ink-soft",
					children: t("product.disclosure")
				})
			]
		})
	] });
}
//#endregion
//#region resources/js/Components/ProductCard.tsx
/**
* The offer-comparison card.
*
* The whole schema exists so this can say "from €X · 3 offers across 2 shops"
* on a single row of one query. One card per physical product — never one per
* offer, which is what makes a comparison site different from a search engine
* pointed at a feed.
*/
function ProductCard({ group }) {
	const { market } = usePage().props;
	const { t, n } = useTranslations();
	const comparable = group.merchantCount > 1;
	return /* @__PURE__ */ jsxs(Link, {
		href: `/${market.key}/p/${group.id}/${group.slug}`,
		className: "group flex flex-col overflow-hidden rounded-card border border-line bg-card transition hover:border-ink/30",
		children: [/* @__PURE__ */ jsxs("div", {
			className: "relative aspect-square overflow-hidden bg-cream",
			children: [
				group.image ? /* @__PURE__ */ jsx("img", {
					src: group.image,
					alt: "",
					loading: "lazy",
					className: "h-full w-full object-contain p-4 transition group-hover:scale-[1.02]",
					onError: (e) => {
						e.currentTarget.style.visibility = "hidden";
					}
				}) : null,
				group.discountPercent !== null && /* @__PURE__ */ jsx("span", {
					className: "absolute top-2 left-2 rounded bg-accent px-2 py-1 text-xs font-medium text-white",
					children: t("product.off", { percent: group.discountPercent })
				}),
				!group.inStock && /* @__PURE__ */ jsx("span", {
					className: "absolute top-2 right-2 rounded bg-ink/70 px-2 py-1 text-xs text-white",
					children: t("product.out_of_stock")
				})
			]
		}), /* @__PURE__ */ jsxs("div", {
			className: "flex flex-1 flex-col p-4",
			children: [
				group.brand && /* @__PURE__ */ jsx("div", {
					className: "text-xs tracking-wide text-ink-soft uppercase",
					children: group.brand
				}),
				/* @__PURE__ */ jsx("h3", {
					className: "mt-1 line-clamp-2 text-sm font-medium",
					children: group.title
				}),
				/* @__PURE__ */ jsxs("div", {
					className: "mt-auto pt-3",
					children: [group.minPrice !== null && /* @__PURE__ */ jsxs("div", {
						className: "flex items-baseline gap-1.5",
						children: [comparable && /* @__PURE__ */ jsx("span", {
							className: "text-xs text-ink-soft",
							children: t("product.from")
						}), /* @__PURE__ */ jsx("span", {
							className: "text-lg font-semibold",
							children: formatPrice(group.minPrice, market)
						})]
					}), /* @__PURE__ */ jsxs("div", {
						className: `mt-0.5 text-xs ${comparable ? "font-medium text-sage" : "text-ink-soft"}`,
						children: [
							group.offerCount === 1 ? t("product.one_offer") : t("product.offers", { count: n(group.offerCount) }),
							" · ",
							comparable ? t("product.across_shops", { count: n(group.merchantCount) }) : t("product.one_shop")
						]
					})]
				})
			]
		})]
	});
}
//#endregion
//#region resources/js/Pages/Search.tsx
var Search_exports = /* @__PURE__ */ __exportAll({ default: () => Search });
function Search({ q, filters, sort, view, facets, results, lanes, emptyBecauseOfFilters }) {
	const { market } = usePage().props;
	const { t, n } = useTranslations();
	const [term, setTerm] = useState(q);
	const base = `/${market.key}/search`;
	/**
	* Every filter is a link, not a form post.
	*
	* That keeps the result set in the URL, so it is shareable, bookmarkable
	* and survives a back button — which a filter panel that lives in component
	* state does not.
	*/
	function go(changes) {
		const next = {
			...filters,
			...changes
		};
		if (!("page" in changes)) delete next.page;
		Object.keys(next).forEach((k) => {
			const v = next[k];
			if (v === null || v === void 0 || v === "" || v === false) delete next[k];
		});
		router.get(base, next, {
			preserveScroll: true,
			preserveState: true
		});
	}
	return /* @__PURE__ */ jsxs(Fragment, { children: [
		/* @__PURE__ */ jsx(Head, { title: q ? t("search.results_for", { term: q }) : t("search.title") }),
		/* @__PURE__ */ jsxs("form", {
			onSubmit: (e) => {
				e.preventDefault();
				go({ q: term });
			},
			className: "flex gap-2",
			role: "search",
			children: [/* @__PURE__ */ jsx("input", {
				type: "search",
				value: term,
				onChange: (e) => setTerm(e.target.value),
				placeholder: t("search.placeholder"),
				"aria-label": t("search.title"),
				className: "flex-1 rounded-lg border border-line bg-card px-4 py-3"
			}), /* @__PURE__ */ jsx("button", {
				className: "rounded-lg bg-accent px-5 py-3 font-medium text-white hover:bg-accent-dark",
				children: t("search.submit")
			})]
		}),
		/* @__PURE__ */ jsxs("div", {
			className: "mt-8 grid gap-8 lg:grid-cols-[16rem_1fr]",
			children: [/* @__PURE__ */ jsxs("aside", {
				"aria-label": t("search.filters"),
				className: "space-y-6 text-sm",
				children: [
					/* @__PURE__ */ jsx(Toggle, {
						label: t("search.in_stock_only"),
						checked: filters.in_stock !== "0",
						onChange: (v) => go({ in_stock: v ? null : "0" })
					}),
					/* @__PURE__ */ jsx(Toggle, {
						label: t("search.discounted_only"),
						checked: filters.discounted === "1",
						onChange: (v) => go({ discounted: v ? "1" : null })
					}),
					/* @__PURE__ */ jsx(Toggle, {
						label: t("search.comparable_only"),
						checked: filters.comparable === "1",
						onChange: (v) => go({ comparable: v ? "1" : null })
					}),
					facets.brands.length > 0 && /* @__PURE__ */ jsx(Facet, {
						title: t("search.brand"),
						items: facets.brands.map((b) => ({
							key: b.value,
							label: b.value,
							count: b.count,
							active: [].concat(filters.brand ?? []).includes(b.value)
						})),
						onToggle: (key, active) => {
							const current = [].concat(filters.brand ?? []);
							go({ brand: active ? current.filter((b) => b !== key) : [...current, key] });
						},
						format: n
					}),
					facets.merchants.length > 0 && /* @__PURE__ */ jsx(Facet, {
						title: t("search.shop"),
						items: facets.merchants.map((m) => ({
							key: String(m.id),
							label: m.name,
							count: m.count,
							active: [].concat(filters.merchant ?? []).map(String).includes(String(m.id))
						})),
						onToggle: (key, active) => {
							const current = [].concat(filters.merchant ?? []).map(String);
							go({ merchant: active ? current.filter((m) => m !== key) : [...current, key] });
						},
						format: n
					})
				]
			}), /* @__PURE__ */ jsxs("section", { children: [
				/* @__PURE__ */ jsxs("div", {
					className: "mb-4 flex flex-wrap items-center gap-3",
					children: [/* @__PURE__ */ jsxs("p", {
						className: "text-sm text-ink-soft",
						"aria-live": "polite",
						children: [q ? t("search.results_for", { term: q }) : t("search.browse"), results.total > 0 && ` · ${t("search.count", { count: n(results.total) })}`]
					}), /* @__PURE__ */ jsxs("div", {
						className: "ml-auto flex items-center gap-2",
						children: [
							/* @__PURE__ */ jsx("label", {
								className: "sr-only",
								htmlFor: "sort",
								children: t("search.sort")
							}),
							/* @__PURE__ */ jsxs("select", {
								id: "sort",
								value: sort,
								onChange: (e) => go({ sort: e.target.value }),
								className: "rounded border border-line bg-card px-2 py-1.5 text-sm",
								children: [
									/* @__PURE__ */ jsx("option", {
										value: "relevance",
										children: t("search.sort_relevance")
									}),
									/* @__PURE__ */ jsx("option", {
										value: "price_asc",
										children: t("search.sort_price_asc")
									}),
									/* @__PURE__ */ jsx("option", {
										value: "price_desc",
										children: t("search.sort_price_desc")
									}),
									/* @__PURE__ */ jsx("option", {
										value: "discount",
										children: t("search.sort_discount")
									}),
									/* @__PURE__ */ jsx("option", {
										value: "newest",
										children: t("search.sort_newest")
									})
								]
							}),
							/* @__PURE__ */ jsx("div", {
								className: "flex rounded border border-line text-sm",
								children: ["grid", "store"].map((v) => /* @__PURE__ */ jsx("button", {
									onClick: () => go({ view: v === "grid" ? null : v }),
									"aria-pressed": view === v,
									className: `px-3 py-1.5 ${view === v ? "bg-ink text-cream" : ""}`,
									children: t(`search.view_${v}`)
								}, v))
							})
						]
					})]
				}),
				results.total === 0 ? /* @__PURE__ */ jsxs("div", {
					className: "rounded-card border border-line bg-card p-8 text-center",
					children: [/* @__PURE__ */ jsx("p", {
						className: "font-medium",
						children: emptyBecauseOfFilters ? t("search.empty_filters") : t("search.empty", { term: q })
					}), emptyBecauseOfFilters ? /* @__PURE__ */ jsx(Link, {
						href: `${base}?q=${encodeURIComponent(q)}`,
						className: "mt-3 inline-block text-accent underline",
						children: t("search.clear_filters")
					}) : /* @__PURE__ */ jsx("p", {
						className: "mt-2 text-sm text-ink-soft",
						children: t("search.empty_hint")
					})]
				}) : view === "store" && lanes ? /* @__PURE__ */ jsx("div", {
					className: "space-y-8",
					children: Object.entries(lanes).map(([shop, items]) => /* @__PURE__ */ jsxs("div", { children: [/* @__PURE__ */ jsx("h2", {
						className: "mb-3 font-medium",
						children: shop
					}), /* @__PURE__ */ jsx("div", {
						className: "grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4",
						children: items.map((g) => /* @__PURE__ */ jsx(ProductCard, { group: g }, g.id))
					})] }, shop))
				}) : /* @__PURE__ */ jsx("div", {
					className: "grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4",
					children: results.items.map((g) => /* @__PURE__ */ jsx(ProductCard, { group: g }, g.id))
				}),
				results.lastPage > 1 && view === "grid" && /* @__PURE__ */ jsxs("nav", {
					className: "mt-8 flex items-center justify-center gap-4 text-sm",
					children: [
						/* @__PURE__ */ jsx("button", {
							disabled: results.currentPage <= 1,
							onClick: () => go({ page: results.currentPage - 1 }),
							className: "rounded border border-line px-3 py-1.5 disabled:opacity-40",
							children: t("search.previous")
						}),
						/* @__PURE__ */ jsx("span", {
							className: "text-ink-soft",
							children: t("search.page_of", {
								current: n(results.currentPage),
								last: n(results.lastPage)
							})
						}),
						/* @__PURE__ */ jsx("button", {
							disabled: results.currentPage >= results.lastPage,
							onClick: () => go({ page: results.currentPage + 1 }),
							className: "rounded border border-line px-3 py-1.5 disabled:opacity-40",
							children: t("search.next")
						})
					]
				})
			] })]
		})
	] });
}
function Toggle({ label, checked, onChange }) {
	return /* @__PURE__ */ jsxs("label", {
		className: "flex cursor-pointer items-center gap-2",
		children: [/* @__PURE__ */ jsx("input", {
			type: "checkbox",
			checked,
			onChange: (e) => onChange(e.target.checked),
			className: "accent-accent"
		}), /* @__PURE__ */ jsx("span", { children: label })]
	});
}
function Facet({ title, items, onToggle, format }) {
	return /* @__PURE__ */ jsxs("div", { children: [/* @__PURE__ */ jsx("h2", {
		className: "mb-2 font-medium",
		children: title
	}), /* @__PURE__ */ jsx("ul", {
		className: "space-y-1",
		children: items.map((item) => /* @__PURE__ */ jsx("li", { children: /* @__PURE__ */ jsxs("label", {
			className: "flex cursor-pointer items-center gap-2",
			children: [
				/* @__PURE__ */ jsx("input", {
					type: "checkbox",
					checked: item.active,
					onChange: () => onToggle(item.key, item.active),
					className: "accent-accent"
				}),
				/* @__PURE__ */ jsx("span", {
					className: "flex-1 truncate",
					children: item.label
				}),
				/* @__PURE__ */ jsx("span", {
					className: "text-xs text-ink-soft",
					children: format(item.count)
				})
			]
		}) }, item.key))
	})] });
}
//#endregion
//#region resources/js/Layouts/SiteLayout.tsx
function SiteLayout({ children }) {
	const { market, markets, auth } = usePage().props;
	const { t } = useTranslations();
	const base = `/${market.key}`;
	const nav = [
		{
			href: `${base}/search`,
			label: t("nav.search")
		},
		{
			href: `${base}/gift`,
			label: t("nav.gift")
		},
		{
			href: `${base}/daily`,
			label: t("nav.daily")
		},
		{
			href: `${base}/guides`,
			label: t("nav.guides")
		}
	];
	return /* @__PURE__ */ jsxs("div", {
		className: "flex min-h-screen flex-col",
		children: [
			/* @__PURE__ */ jsx("a", {
				href: "#main",
				className: "sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-accent focus:px-3 focus:py-2 focus:text-white",
				children: t("nav.skip")
			}),
			/* @__PURE__ */ jsx("header", {
				className: "border-b border-line",
				children: /* @__PURE__ */ jsxs("div", {
					className: "mx-auto flex max-w-6xl items-center gap-6 px-4 py-4",
					children: [
						/* @__PURE__ */ jsx(Link, {
							href: base,
							className: "text-lg font-semibold tracking-tight",
							children: "Brandcoves"
						}),
						/* @__PURE__ */ jsx("nav", {
							className: "hidden gap-5 text-sm text-ink-soft sm:flex",
							"aria-label": t("nav.main"),
							children: nav.map((item) => /* @__PURE__ */ jsx(Link, {
								href: item.href,
								className: "hover:text-ink",
								children: item.label
							}, item.href))
						}),
						/* @__PURE__ */ jsxs("div", {
							className: "ml-auto flex items-center gap-3",
							children: [
								/* @__PURE__ */ jsx("label", {
									className: "sr-only",
									htmlFor: "market-switcher",
									children: t("nav.choose_market")
								}),
								/* @__PURE__ */ jsx("select", {
									id: "market-switcher",
									className: "rounded border border-line bg-card px-2 py-1 text-sm",
									value: market.key,
									onChange: (e) => {
										window.location.href = `/${e.target.value}`;
									},
									children: markets.map((m) => /* @__PURE__ */ jsx("option", {
										value: m.key,
										children: m.label
									}, m.key))
								}),
								auth.user ? /* @__PURE__ */ jsx(Link, {
									href: `${base}/lists`,
									className: "text-sm hover:text-ink",
									children: t("nav.lists")
								}) : /* @__PURE__ */ jsx(Link, {
									href: "/login",
									className: "text-sm hover:text-ink",
									children: t("nav.sign_in")
								})
							]
						})
					]
				})
			}),
			/* @__PURE__ */ jsx("main", {
				id: "main",
				className: "mx-auto w-full max-w-6xl flex-1 px-4 py-10",
				children
			}),
			/* @__PURE__ */ jsx("footer", {
				className: "border-t border-line",
				children: /* @__PURE__ */ jsx("div", {
					className: "mx-auto max-w-6xl px-4 py-6 text-sm text-ink-soft",
					children: /* @__PURE__ */ jsx("p", { children: t("footer.affiliate") })
				})
			})
		]
	});
}
//#endregion
//#region resources/js/ssr.tsx
/**
* Server-side rendering.
*
* Without this the HTML a crawler receives is an empty <div id="app"> plus a
* JSON blob. Google will often execute the JS and eventually index it, but
* "eventually, if the render budget allows" is a poor foundation for a site
* whose entire growth model is search — and every other crawler (Bing, social
* card scrapers, LLM crawlers) is far less forgiving.
*
* Runs as its own container in production: `php artisan inertia:start-ssr`.
* If it dies, Laravel falls back to client rendering — the site stays up and
* only loses the pre-rendered HTML.
*/
createServer((page) => createInertiaApp({
	page,
	render: ReactDOMServer.renderToString,
	title: (title) => title ? `${title} · Brandcoves` : "Brandcoves",
	resolve: async (name) => {
		const module = (/* @__PURE__ */ Object.assign({
			"./Pages/Home.tsx": Home_exports,
			"./Pages/Product.tsx": Product_exports,
			"./Pages/Search.tsx": Search_exports
		}))[`./Pages/${name}.tsx`];
		if (!module) throw new Error(`Inertia page not found: ./Pages/${name}.tsx`);
		module.default.layout ??= (child) => /* @__PURE__ */ jsx(SiteLayout, { children: child });
		return module;
	},
	setup: ({ App, props }) => /* @__PURE__ */ jsx(App, { ...props })
}));
//#endregion
export {};
