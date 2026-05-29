#!/usr/bin/env python3
"""
Thin yfinance CLI wrapper — called by YfinanceProvider via Symfony Process.
Outputs a single JSON object to stdout; errors go to stderr with exit code 1.

Usage:
  python3 yfinance_fetch.py quote     SYMBOL
  python3 yfinance_fetch.py dividends SYMBOL
  python3 yfinance_fetch.py history   SYMBOL YYYY-MM-DD
  python3 yfinance_fetch.py fx        BASE QUOTE        (e.g. EUR GBP)
"""

import sys
import json
import yfinance as yf
from datetime import datetime, date


def fail(msg):
    print(msg, file=sys.stderr)
    sys.exit(1)


def to_iso(val):
    if hasattr(val, 'isoformat'):
        return val.isoformat()
    return str(val)


def cmd_quote(symbol):
    t = yf.Ticker(symbol)
    info = t.fast_info
    hist = t.history(period='2d')
    if hist.empty:
        fail(f"No price data for {symbol}")
    row = hist.iloc[-1]
    currency = getattr(info, 'currency', None) or (t.info or {}).get('currency', '')
    print(json.dumps({
        'symbol': symbol,
        'price': float(row['Close']),
        'currency': currency,
        'date': to_iso(hist.index[-1].date()),
    }))


def cmd_dividends(symbol):
    t = yf.Ticker(symbol)
    divs = t.dividends
    currency = (t.fast_info.__dict__.get('currency') or
                (t.info or {}).get('currency', ''))
    rows = []
    for dt, amount in divs.items():
        rows.append({
            'date': to_iso(dt.date()),
            'amount': float(amount),
            'currency': currency,
        })
    print(json.dumps(rows))


def cmd_history(symbol, from_date):
    t = yf.Ticker(symbol)
    hist = t.history(start=from_date)
    currency = (t.fast_info.__dict__.get('currency') or
                (t.info or {}).get('currency', ''))
    rows = []
    for dt, row in hist.iterrows():
        rows.append({
            'date': to_iso(dt.date()),
            'close': float(row['Close']),
            'currency': currency,
        })
    print(json.dumps(rows))


def cmd_fx(base, quote):
    # yfinance FX ticker: EURUSD=X means 1 EUR = X USD
    ticker = f"{base}{quote}=X"
    t = yf.Ticker(ticker)
    hist = t.history(period='2d')
    if hist.empty:
        fail(f"No FX data for {ticker}")
    rate = float(hist.iloc[-1]['Close'])
    print(json.dumps({
        'base': base,
        'quote': quote,
        'rate': rate,
        'date': to_iso(hist.index[-1].date()),
    }))


if __name__ == '__main__':
    args = sys.argv[1:]
    if not args:
        fail("No command given")

    cmd = args[0]
    try:
        if cmd == 'quote' and len(args) == 2:
            cmd_quote(args[1])
        elif cmd == 'dividends' and len(args) == 2:
            cmd_dividends(args[1])
        elif cmd == 'history' and len(args) == 3:
            cmd_history(args[1], args[2])
        elif cmd == 'fx' and len(args) == 3:
            cmd_fx(args[1], args[2])
        else:
            fail(f"Unknown command or wrong args: {args}")
    except Exception as e:
        fail(str(e))
