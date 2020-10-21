





```bash
# Generate unique numbers
# Start with a leading 1 to avoid confusing, errorprone zero handling in csv
echo '"code","page","comment"' > verification_codes.csv
shuf -i 100000000000-999999999999 -n 100 >> verification_codes.csv
```

