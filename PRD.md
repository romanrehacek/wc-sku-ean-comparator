# PRD: WC SKU/EAN Comparator

## 1. Názov Pluginu
- **WC SKU/EAN Comparator**

---

## 2. Cieľ
Plugin slúži na import cenníkov vo formáte CSV/XLS/XLSX, porovnanie produktov s existujúcimi WooCommerce produktmi podľa SKU, EAN a názvu, a generovanie výstupných CSV súborov a výsledkov priamo v administrácii.  

Funkcie sú **informatívne** – žiadne automatické mazanie alebo zmeny produktov v e-shope.

---

## 3. Požiadavky na prostredie
- WordPress ≥ 6.x  
- WooCommerce ≥ 7.x *(nutné pre fungovanie značiek a produktov)*  
- PHP ≥ 8.0  
- Adresár pre ukladanie súborov: `wp-content/uploads/wc-sku-ean-comparator/`  
- Dočasný adresár `_temp` v adresári pluginu na testovanie/nahrávanie súborov  
- AJAX podpora pre spracovanie dát v dávkach, aby sa neprekročil limit PHP

---

## 4. Umístnenie v administrácii
- Menu: **Nástroje → WC SKU/EAN Comparator**
- Podmenu:  
  - **Nové porovnanie** – spustenie nového porovnania  
  - **História porovnaní** – zoznam predchádzajúcich porovnaní s možnosťou detailného zobrazenia, editácie, mazania a opätovného spustenia

---

## 5. Funkcionality pluginu

### 5.1 Import súborov
- Podporované formáty: `CSV`, `XLS`, `XLSX`  
- Možnosti:  
  1. Nahrať nový súbor  
  2. Vybrať súbor už uložený v adresári pluginu  
- Funkcie:  
  - Overenie duplikátu názvu súboru a upozornenie na prepísanie  
  - Odstránenie súborov zo zoznamu  
  - Pri duplicitných listoch XLS/XLSX sa pridelí postfix číslom (`Sheet1`, `Sheet1_2`…)

### 5.2 Výber listu (pre XLS/XLSX)
- Ak súbor obsahuje viac listov, používateľ si vyberie, ktorý list spracovať

### 5.3 Výber značiek produktov
- Povinná voľba z WooCommerce taxonomy `product_brand`  
- Možnosť vybrať viac značiek  
- Na základe značiek sa generuje názov výstupného CSV + dátum a čas

### 5.4 Mapovanie stĺpcov
- Používateľ určí:  
  - Stĺpce pre SKU (môže byť viac stĺpcov)  
  - Stĺpce pre EAN (môže byť viac stĺpcov)  
  - Stĺpce pre názov produktu (môže byť viac stĺpcov, automatické spájanie do výsledného názvu)  
- Drag-n-drop alebo výber stĺpcov  
- Podobne ako WP All Import: hodnoty sa priraďujú ku konkrétnym poliam

### 5.5 Spustenie porovnania
- Načítanie všetkých SKU, EAN a názvov produktov do pamäte **v dávkach cez AJAX**, aby sa predišlo PHP limitom  
- Porovnanie:  
  1. **Cenník → e-shop:** zistí, ktoré produkty z cenníka sa nachádzajú alebo nenachádzajú v e-shope  
  2. **E-shop (vybrané značky) → cenník:** zistí produkty v e-shope, ktoré už nie sú v cenníku  

- **Výsledok**:  
  - Zobrazuje sa priamo v admin GUI  
  - Ukladá sa do databázy (história porovnaní)

### 5.6 Výstupné CSV
- Dva súbory:  
  1. **Cenník → e-shop**  
  2. **E-shop → cenník** (vybrané značky)  

- Obsah CSV:  
  - `názov` produktu z cenníka  
  - `EAN` z cenníka  
  - `SKU` z cenníka  
  - `info`, či sa produkt našiel v e-shope  
  - `ID` produktu z e-shopu, ak existuje  
  - `názov`, `EAN`, `SKU` z e-shopu  

- CSV sa uloží do pluginového adresára a zobrazí sa link na stiahnutie

### 5.7 Zobrazenie výsledkov v admin GUI
- Výsledok porovnania zobrazený vo forme **dvoch záložiek**:  
  1. **Cenník → e-shop**  
  2. **E-shop → cenník** (vybrané značky)  
- Interaktívne – používateľ môže filtrovať, vyhľadávať a scrollovať výsledky  
- AJAX spracovanie pre dávkové načítanie a zobrazenie

### 5.8 História porovnaní
- Vlastná databázová tabuľka:  
  - ID porovnania  
  - Názov súboru  
  - Dátum a čas spustenia  
  - Vybrané značky  
  - Mapovanie stĺpcov  
  - Štatistika výsledku  
  - Linky na výstupné CSV  

- Funkcie:  
  - Zobrazenie zoznamu porovnaní (stránkovanie, zoradenie podľa dátumu)  
  - Zobrazenie detailu porovnania (štatistika, CSV, výsledky)  
  - Editácia parametrov a opätovné spustenie  
  - Mazanie porovnaní

---

## 6. Ukladanie a dočasné súbory
- Dočasný adresár `_temp` na experimentálne nahrávanie súborov  
- Produkčné CSV a importované súbory v:  
  `wp-content/uploads/wc-sku-ean-comparator/`  

**Poznámka:** Súbory a kód v `_temp` sú iba na inšpiráciu/testovanie, nie pevná štruktúra.

---

## 7. Bezpečnosť
- Overenie typu súboru a veľkosti pri nahrávaní  
- Sanitizácia vstupov (stĺpce, mapovanie, značky) pred zápisom do databázy  
- Prístup len pre administrátora WordPress
