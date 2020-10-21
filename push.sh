git add -A
if [ $# -eq 0 ]
  then
    git commit -m "New changes"
  else
    git commit -m "$1"
fi
git push